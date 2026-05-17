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
| **spatie/laravel-typescript-transformer** | `route('about')` from your wrapper | TS helper module |

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

`LaravelRouteTransformedProvider` (v3+) generates a typed `route()`
helper as a static `.ts` file at build/watch time, with the same call
shape as Ziggy (`route(name, params?, absolute?)`). Because the file is
emitted ahead of time, there's no per-request hook to intercept the way
the Ziggy adapter does - the locale-aware variant pick has to happen
client-side. The wrapper mirrors the server-side resolver in
`Illuminate\Routing\UrlGenerator` one-to-one: probe each candidate name
against the manifest, take the first that exists.

The generator emits the `routes` lookup object but doesn't export it
([source][1]), so the wrapper detects existence by inspecting the
return value: a name that isn't in the manifest produces `/undefined`
(or `<origin>/undefined` for absolute URLs), because the generated body
does `'/' + routes[name]`. That's a working primitive today; for a
forward-compatible note see ["Caveats"](#caveats) below.

```ts
// resources/js/route.ts
import { route as baseRoute } from '@/helpers/route';

const DEFAULT_LOCALE = 'en';   // mirror config('app.fallback_locale')
const HIDE_DEFAULT   = true;   // mirror localizer.hide_default_locale

// Use whatever locale source you have. With Inertia, share it from the
// server: HandleInertiaRequests::share() returns ['locale' => app()->getLocale()]
// and you read usePage().props.locale here.
function currentLocale(): string {
    return document.documentElement.lang || DEFAULT_LOCALE;
}

// Recover the route-name union from the exported function signature,
// since `RouteParameters` isn't exported.
type RouteName = Parameters<typeof baseRoute>[0];

// "Does this route name exist in the manifest?" by inspecting the
// generated body's '/' + routes[name] miss behavior.
function exists(name: string): boolean {
    return baseRoute(name as RouteName, {} as any, false) !== '/undefined';
}

export function route(
    name: string,
    parameters: Record<string, any> = {},
    absolute: boolean = true,
): string {
    const { locale = currentLocale(), ...rest } = parameters;

    // 1. Exact match - plain Laravel routes registered outside the
    //    locale macros (admin.dashboard, api.health, etc.).
    if (exists(name)) {
        return baseRoute(name as RouteName, parameters as any, absolute);
    }

    // 2. hide_default branch - drop the prefix when both target and
    //    current app are in the default locale and there's an
    //    unprefixed variant registered.
    const withoutN = `without_locale.${name}`;
    if (HIDE_DEFAULT && locale === DEFAULT_LOCALE && exists(withoutN)) {
        return baseRoute(withoutN as RouteName, rest as any, absolute);
    }

    // 3. Route::localize() variant.
    const withN = `with_locale.${name}`;
    if (exists(withN)) {
        return baseRoute(withN as RouteName, { ...rest, locale } as any, absolute);
    }

    // 4. Route::translate() variant - locale baked into the URI, no
    //    locale parameter.
    const translatedN = `translated_${locale}.${name}`;
    if (exists(translatedN)) {
        return baseRoute(translatedN as RouteName, rest as any, absolute);
    }

    throw new Error(`Route "${name}" not found for locale "${locale}"`);
}
```

Usage matches Laravel's server-side `route()` one-to-one - just import
from the wrapper instead of `@/helpers/route` directly:

```ts
import { route } from '@/route';

route('about');                   // 'https://app.test/de/about' (current = de, Route::localize())
route('about', { locale: 'fr' }); // 'https://app.test/fr/about'
route('about', { locale: 'en' }); // 'https://app.test/about'   (= default, hide_default)
route('about', {}, false);        // '/de/about' (relative)
route('admin.dashboard');         // 'https://app.test/admin/dashboard' (plain route, no prefix)
route('contact');                 // 'https://app.test/kontakt'  (Route::translate(['en' => 'contact', 'de' => 'kontakt']))
```

### Caveats

The `'/undefined'` detection depends on the body of the generated
`route()`. As of `spatie/laravel-typescript-transformer` v3, a miss
produces `'/' + undefined` and the function returns; if a future release
throws or returns `''` instead, the `exists()` helper here will need a
one-line adjustment. The cleaner long-term fix is for the generator to
export `routes` or emit a dedicated `routeExists()` helper - tracked
upstream in [spatie/typescript-transformer discussion #151][2]. Until
then, the body-inspection approach is what makes the wrapper robust
against Plain-routes-vs-`Route::localize()`-vs-`Route::translate()`
without a hand-maintained registry.

[1]: https://github.com/spatie/laravel-typescript-transformer/blob/main/src/TransformedProviders/LaravelRouteTransformedProvider.php
[2]: https://github.com/spatie/typescript-transformer/discussions/151

## Cross-locale URLs and SEO

Both adapters above optimize for **the current request's locale**, ideal
for in-page links. For `hreflang` tags, canonical URLs and sitemaps you
want all locales at once and a guaranteed canonical form (no 301
round-trip on the default locale). Render those server-side via
`Route::localizedUrl($locale)` regardless of which JS helper you use.
See [Template Helpers](/template-helpers).
