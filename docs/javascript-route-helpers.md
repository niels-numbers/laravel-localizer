# JavaScript Route Helpers

Client-side URL builders like [Ziggy](https://github.com/tighten/ziggy)
and [Laravel Wayfinder](https://github.com/laravel/wayfinder) don't go
through this package's `UrlGenerator` override; the locale-aware variant
selection that `route('about')` does on the server doesn't happen in JS
automatically. With a small adapter per stack you get the same DX as on
the server.

| Stack | What you write in JS | What you install |
|---|---|---|
| **Ziggy** | `route('about')`, unchanged | One container binding |
| **Wayfinder** | `localizedRoute('about')` | TS helper module |
| **spatie/laravel-typescript-transformer** | Not currently compatible ([why](#spatie-laravel-typescript-transformer)) | - |

## Ziggy

Bind the package adapter in `AppServiceProvider::register()`. Pick the
line that matches your Ziggy version (`composer show | grep ziggy`):

### `tighten/ziggy` v2+

```php
$this->app->bind(
    \Tighten\Ziggy\BladeRouteGenerator::class,
    \NielsNumbers\LaravelLocalizer\Routing\LocalizerBladeRouteGeneratorV2::class,
);
```

> **Vite alias note.** If your app aliases `'ziggy-js'` to a file inside
> the Composer-vendored Ziggy package (instead of installing the npm
> package), the v2 `dist/` layout no longer ships `vue.es.js`. Point the
> alias at `vendor/tightenco/ziggy/dist/index.esm.js` - that bundle
> exports `ZiggyVue`, `route`, and `useRoute`. The old v1 file was
> `vendor/tightenco/ziggy/dist/vue.es.js`.

### `tightenco/ziggy` v1

```php
$this->app->bind(
    \Tightenco\Ziggy\BladeRouteGenerator::class,
    \NielsNumbers\LaravelLocalizer\Routing\LocalizerBladeRouteGeneratorV1::class,
);
```

### Verify

After binding, `@routes` in your Blade root view ships a locale-aware
manifest. Sanity check from `php artisan tinker`:

```php
// v2+: NielsNumbers\LaravelLocalizer\Routing\LocalizerBladeRouteGeneratorV2
get_class(app(\Tighten\Ziggy\BladeRouteGenerator::class));

// v1:  NielsNumbers\LaravelLocalizer\Routing\LocalizerBladeRouteGeneratorV1
get_class(app(\Tightenco\Ziggy\BladeRouteGenerator::class));
```

### Locale defaults at runtime

Once wired up, `@routes` in your Blade layout (or the Ziggy bridge that
Inertia uses) emits the locale-aware manifest. `URL::defaults(['locale'
=> …])` is set by the `SetLocale` middleware, so Ziggy fills in
`{locale}` placeholders automatically:

```js
// current locale = de
route('about');                   // '/de/about'
route('about', { locale: 'fr' }); // '/fr/about' (explicit override)

// current locale = en (= default, hide_default_locale on)
route('about');                   // '/about'
```

> **Generating Ziggy from a console command?** `URL::defaults(['locale'
> => …])` is only populated by `SetLocale` during an HTTP request, so
> `php artisan ziggy:generate` (or any build-time manifest generation)
> ships with bare `{locale}` placeholders and no default filled in. If
> that's part of your build, wrap it in a custom artisan command that
> calls `App::setLocale($locale)` before invoking the generator, once
> per locale you want to ship.

## Wayfinder: `localizedRoute()` helper

Wayfinder generates typed functions at build time and doesn't read
`URL::defaults`, so a build-time rewrite would break tree-shaking and
lose per-route type inference. Instead, ship a small lookup helper that
wraps the generated modules and mirrors the server-side variant pick:

```ts
// resources/js/localizedRoute.ts
import * as withLocale    from '@/routes/with_locale';
import * as withoutLocale from '@/routes/without_locale';

const DEFAULT_LOCALE = 'en';   // mirror config('app.fallback_locale')
const HIDE_DEFAULT   = true;   // mirror localizer.hide_default_locale

// Use whatever locale source you have. With Inertia, share it from the
// server: HandleInertiaRequests::share() returns ['locale' => app()->getLocale()]
// and you read usePage().props.locale here.
function currentLocale(): string {
    return document.documentElement.lang || DEFAULT_LOCALE;
}

export function localizedRoute<K extends keyof typeof withLocale>(
    name: K,
    params: Record<string, any> = {},
): string {
    const locale = params.locale ?? currentLocale();
    const { locale: _, ...rest } = params;

    if (HIDE_DEFAULT && locale === DEFAULT_LOCALE && (name in withoutLocale)) {
        return (withoutLocale as any)[name].url(rest);
    }
    return (withLocale as any)[name].url({ ...rest, locale });
}
```

```ts
import { localizedRoute } from '@/localizedRoute';

localizedRoute('about');                   // '/de/about' (current = de)
localizedRoute('about', { locale: 'fr' }); // '/fr/about'
localizedRoute('about', { locale: 'en' }); // '/about'   (= default, hide_default)
```

For `Route::translate()` routes, extend the helper with one extra branch
that imports `@/routes/translated_<locale>` and dispatches by the active
locale; same pattern.

## spatie/laravel-typescript-transformer

::: danger Not currently compatible
The locale-aware wrapper cannot work with `spatie/laravel-typescript-transformer`
(v3) today. Its generator (`ResolveRouteCollectionAction`) indexes routes by
controller class - or `[class][method]` - not by route name. Because
`Route::localize()` registers each route twice (a `with_locale.` and a
`without_locale.` variant) on the same controller and method, the second
registration overwrites the first in the generated manifest: only one variant
survives, so the `with_locale.*` names the wrapper would need are never
emitted. (Plain `Route::inertia()` already collapses for the same reason - all
Inertia routes share `\Inertia\Controller`.)

This is a generator limitation, not a localizer bug: Laravel's own
`route:list` is complete and correct, only the generated TypeScript file is
missing routes. A proper fix requires the generator to key by route name.
Tracked upstream in [spatie/laravel-typescript-transformer#87](https://github.com/spatie/laravel-typescript-transformer/issues/87).

Until then, use the [Ziggy](#ziggy) adapter - it iterates the route collection
directly and keeps every variant.
:::

## Cross-locale URLs and SEO

Both adapters above optimize for **the current request's locale**, ideal
for in-page links. For `hreflang` tags, canonical URLs and sitemaps you
want all locales at once and a guaranteed canonical form (no 301
round-trip on the default locale). Render those server-side via
`Route::localizedUrl($locale)` regardless of which JS helper you use.
See [Template Helpers](/template-helpers).
