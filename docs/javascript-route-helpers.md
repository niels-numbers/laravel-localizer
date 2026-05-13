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
client-side, the same shape as the Wayfinder helper above. The wrapper
is slightly simpler though, because the generated `route()` already
knows all three variant namespaces (`with_locale.*`, `without_locale.*`,
`translated_<locale>.*`) as first-class route names:

```ts
// resources/js/route.ts
import { route as baseRoute, type RouteParameters } from '@/helpers/route';

const DEFAULT_LOCALE = 'en';   // mirror config('app.fallback_locale')
const HIDE_DEFAULT   = true;   // mirror localizer.hide_default_locale

// Use whatever locale source you have. With Inertia, share it from the
// server: HandleInertiaRequests::share() returns ['locale' => app()->getLocale()]
// and you read usePage().props.locale here.
function currentLocale(): string {
    return document.documentElement.lang || DEFAULT_LOCALE;
}

// Bare route names = anything registered as `with_locale.<X>`, with the prefix stripped.
type BareName<K = keyof RouteParameters> =
    K extends `with_locale.${infer N}` ? N : never;

type ParamsFor<N extends BareName> =
    RouteParameters[`with_locale.${N}` & keyof RouteParameters];

type LocaleParams<N extends BareName> =
    [ParamsFor<N>] extends [never]
        ? { locale?: string }
        : Omit<ParamsFor<N>, 'locale'> & { locale?: string };

export function route<N extends BareName>(
    name: N,
    parameters?: LocaleParams<N>,
    absolute: boolean = true,
): string {
    const { locale = currentLocale(), ...rest } =
        (parameters ?? {}) as Record<string, any>;

    if (HIDE_DEFAULT && locale === DEFAULT_LOCALE) {
        return baseRoute(
            `without_locale.${name}` as keyof RouteParameters,
            rest as any,
            absolute,
        );
    }

    return baseRoute(
        `with_locale.${name}` as keyof RouteParameters,
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

For `Route::translate()` routes, add one branch at the top of the
wrapper that prefers `translated_<locale>.<name>` when present. Since
`RouteParameters` is erased at runtime, either keep a small `Set` of
route names that have translated variants, or re-export the generated
`routes` object from your typescript-transformer config and check
membership against that.

## Cross-locale URLs and SEO

Both adapters above optimize for **the current request's locale**, ideal
for in-page links. For `hreflang` tags, canonical URLs and sitemaps you
want all locales at once and a guaranteed canonical form (no 301
round-trip on the default locale). Render those server-side via
`Route::localizedUrl($locale)` regardless of which JS helper you use.
See [Template Helpers](/template-helpers).
