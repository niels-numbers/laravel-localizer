> ⚠️ **Experimental - not yet verified end-to-end.**
> The patterns below are written from analysis of how Inertia v2, Ziggy and
> this package interact, but I haven't run a full integration test against
> them yet. Treat this as a working sketch: the direction should be right,
> but expect to debug. Issues / PRs welcome once you've tried it.
>
> The **recommended default** for a language switcher remains a plain `<a>`
> tag with full reload (see [Language Switcher](/language-switcher)). Only
> reach for the setup below if you specifically want SPA navigation across
> language switches.

# Inertia + Language Switcher

Three pitfalls show up when the switcher should navigate SPA-style (Inertia
`<Link>`) instead of forcing a full reload. Address all three and the
switcher behaves like the rest of the app: progress bar, no flash, correct
language everywhere.

## 1. Ship Ziggy routes as an Inertia shared prop

`@routes` renders Ziggy's route table **once** on the initial page load as
a global `const Ziggy = {...}` in the HTML head. On an SPA visit only JSON
comes across the wire; the HTML stays - and so does the route table for
the locale that was rendered initially. `route('about')` keeps resolving
against that frozen snapshot.

With `LocalizerZiggy`, the route table changes per locale (different URIs
for `/about` vs `/de/about`, or with `Route::translate()` even different
paths). The frontend needs to see a fresh table on every visit.

**Fix:** Ship Ziggy as an Inertia shared prop instead of reading it from
the HTML head.

This pattern resolves `Ziggy` through the container, so the
`BladeRouteGenerator` binding from the
[JavaScript Route Helpers](/javascript-route-helpers) page does not
reach it - that binding only swaps the generator that `@routes` calls.
For container-resolved consumers you additionally need:

```php
// app/Providers/AppServiceProvider.php
$this->app->bind(
    \Tighten\Ziggy\Ziggy::class,
    \NielsNumbers\LaravelLocalizer\Routing\LocalizerZiggyV2::class,
);
```

```php
// app/Http/Middleware/HandleInertiaRequests.php
use Tighten\Ziggy\Ziggy;

public function share(Request $request): array
{
    return array_merge(parent::share($request), [
        // ...
        'ziggy' => fn () => app(Ziggy::class)->toArray(),
    ]);
}
```

The closure now produces the locale-correct route table on every visit.

You can drop `@routes` from the HTML root template - the shared prop
takes over. If you drop `@routes`, the `BladeRouteGenerator` binding
is no longer needed either; the `Ziggy::class` binding above stands
alone.

## 2. Make `route()` reactive to `usePage()`

Naive approach: overwrite `globalThis.Ziggy` on every visit. This breaks
the moment **Inertia v2 history-state caching** enters the picture. When
the user navigates to a URL they've visited before (including a forward
click on a `<Link>`), Inertia can restore the page along with its props
from the history cache - without a server round-trip.

A globally held route table is then guaranteed to drift out of sync:
`usePage().props.locale` shows the restored language, but `globalThis.Ziggy`
still holds the table from the last actually-loaded page.

**Fix:** Have `route()` ignore the global and read from
`usePage().props.ziggy` on every call. Resolution is then bound to the
currently mounted page by construction.

```ts
// resources/js/app.ts
import { createInertiaApp, usePage } from '@inertiajs/vue3';
import { route as ziggyRoute } from 'ziggy-js';
import type { App } from 'vue';

const ReactiveZiggy = {
    install(app: App) {
        const route = (name?: string, params?: unknown, absolute?: boolean) => {
            const config = (usePage().props as { ziggy?: unknown }).ziggy;
            return ziggyRoute(name as string, params as never, absolute, config as never);
        };
        app.config.globalProperties.route = route;
        app.provide('route', route);
    },
};

createInertiaApp({
    // ...
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ReactiveZiggy)   // instead of ZiggyVue
            .mount(el);
    },
});
```

No more global to keep in sync. `route('about')` always resolves against
`props.ziggy` of the currently active page.

## 3. Update `<html lang>` on SPA visits

Inertia only swaps the app container on a visit, not the root template -
so `<html lang="...">` stays on the language that was rendered initially.
Browser translations, screen readers and SEO crawlers read the value
literally.

**Fix:** Use `router.on('success', ...)` to update the attribute after
every successful visit.

```ts
import { router } from '@inertiajs/vue3';

router.on('success', (event) => {
    const locale = (event.detail.page.props as { locale?: string }).locale;
    if (locale) {
        document.documentElement.lang = locale.replace('_', '-');
    }
});
```

## 4. Switcher links: use prefixed URLs

The switcher must use `Route::localizedSwitcherUrl($locale)`, not
`Route::localizedUrl($locale)`. The latter returns the **canonical**,
unprefixed form (`/about`) for the default locale - correct for
hreflang/sitemap, but no good for switching: without a locale prefix in
the URL, `SetLocale` has nothing to read, falls back to the persisted
session locale, and the switch is a no-op.

`localizedSwitcherUrl()` always emits the prefixed form, even for the
default locale (`/en/about`); `RedirectLocale` then strips it on the
follow-up request to land on the canonical form.

For Inertia `<Link>`, prefer the **relative** variant on top of that:
absolute URLs (even same-origin) are more likely to be treated as external
links and trigger a full reload.

```php
'localeSwitchUrls' => fn () => Route::isLocalized()
    ? collect(config('localizer.supported_locales'))
        ->mapWithKeys(fn ($l) => [$l => Route::localizedSwitcherUrl($l, false)])
        ->all()
    : null,
```

```vue
<Link
    v-for="code in supportedLocales"
    :key="code"
    :href="localeSwitchUrls[code]"
    :class="locale === code ? 'active' : ''"
>
    {{ code.toUpperCase() }}
</Link>
```

## Summary

| Problem | Symptom | Fix |
|---|---|---|
| Static `@routes` table | `route('about')` always resolves to the initial locale | Ship Ziggy as a shared prop |
| Inertia v2 history-state cache | Language flips back and forth across URLs | Read `route()` from `usePage().props.ziggy` |
| Root template not re-rendered | `<html lang>` stays on the initial locale | `router.on('success', …)` sets the attribute |
| Default-locale link without prefix | Switching to default locale has no effect | `localizedSwitcherUrl()` instead of `localizedUrl()` |
| Absolute URLs in `<Link>` | Full reload instead of SPA visit | `Route::localizedSwitcherUrl($l, false)` |
