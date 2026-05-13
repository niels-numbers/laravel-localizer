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
    \Tighten\Ziggy\Ziggy::class,
    \NielsNumbers\LaravelLocalizer\Routing\LocalizerZiggyV2::class,
);
```

### `tightenco/ziggy` v1

v1 instantiates Ziggy directly inside its `BladeRouteGenerator`,
bypassing the container. The generator itself has to be replaced:

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
// v2+: NielsNumbers\LaravelLocalizer\Routing\LocalizerZiggyV2
get_class(app(\Tighten\Ziggy\Ziggy::class));

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
client-side. The wrapper is roughly Wayfinder-shaped, but with one
quirk: the generated module exports only the `route` function, not the
underlying `routes` object or the `RouteParameters` type ([source][1]).
That's enough for `Route::localize()` routes; `Route::translate()`
routes need a small extra dance.

### `Route::localize()` only

If your app only uses `Route::localize()`, the wrapper is fully typed
without depending on any non-exported members. Use the
`Parameters<typeof route>` utility to derive route names from the
exported function signature:

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

// Bare names = anything registered as `with_locale.<X>`, prefix stripped.
type BareName<K = RouteName> =
    K extends `with_locale.${infer N}` ? N : never;

export function route<N extends BareName>(
    name: N,
    parameters: Record<string, any> = {},
    absolute: boolean = true,
): string {
    const { locale = currentLocale(), ...rest } = parameters;

    if (HIDE_DEFAULT && locale === DEFAULT_LOCALE) {
        return baseRoute(
            `without_locale.${name}` as RouteName,
            rest as any,
            absolute,
        );
    }

    return baseRoute(
        `with_locale.${name}` as RouteName,
        { ...rest, locale } as any,
        absolute,
    );
}
```

Usage matches Laravel's server-side `route()` one-to-one - just import
from the wrapper instead of `@/helpers/route` directly:

```ts
import { route } from '@/route';

route('about');                   // 'https://app.test/de/about' (current = de)
route('about', { locale: 'fr' }); // 'https://app.test/fr/about'
route('about', { locale: 'en' }); // 'https://app.test/about' (= default, hide_default)
route('about', {}, false);        // '/de/about' (relative)
```

### With `Route::translate()`

`Route::translate()` registers each variant under
`translated_<locale>.<name>` and does **not** register a `with_locale.*`
counterpart. To know whether to dispatch `with_locale.about` or
`translated_de.about`, the wrapper needs a runtime existence check
against the generated routes manifest - and that's exactly the object
`spatie/laravel-typescript-transformer` doesn't expose.

Until [the upstream export][1] lands, maintain the list manually:

```ts
// Names that were registered with Route::translate() instead of Route::localize()
const TRANSLATED_ROUTES = new Set<string>(['about', 'contact']);

// Insert at the top of route() above, before the hide-default branch:
if (TRANSLATED_ROUTES.has(name)) {
    return baseRoute(`translated_${locale}.${name}` as RouteName, rest as any, absolute);
}
```

The set has to be kept in sync with the PHP side by hand. If you have
many translated routes, an alternative is to dump the list at build
time (e.g. from an Artisan command that emits a small JSON file the
wrapper imports) so you don't track it in two places.

[1]: https://github.com/spatie/laravel-typescript-transformer/blob/main/src/TransformedProviders/LaravelRouteTransformedProvider.php

## Cross-locale URLs and SEO

Both adapters above optimize for **the current request's locale**, ideal
for in-page links. For `hreflang` tags, canonical URLs and sitemaps you
want all locales at once and a guaranteed canonical form (no 301
round-trip on the default locale). Render those server-side via
`Route::localizedUrl($locale)` regardless of which JS helper you use.
See [Template Helpers](/template-helpers).
