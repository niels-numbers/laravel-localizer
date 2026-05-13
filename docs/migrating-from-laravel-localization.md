# Migrating from `mcamara/laravel-localization`

This guide walks through swapping `mcamara/laravel-localization` for
`niels-numbers/laravel-localizer` on an existing app. The two packages
solve the same problem - locale-prefixed routes plus auto-detection -
but the wiring differs.

## Why migrate

Issues from the original's dynamic-routes architecture that this
rewrite fixes at the design level:

- **`route:cache` works.** The original package generated routes per request,
  forcing a custom `route:trans:cache` workaround instead of Laravel's
  built-in command. Here every route is registered statically
  (`with_locale.*` + `without_locale.*`), so plain `route:cache` works
  on every supported Laravel version. The `route()` helper still takes
  the original name (`route('about')`); the package picks the
  locale-aware variant automatically.
- **Translated routes switch reliably.** The original built
  locale-switched URLs by parsing the current request URI as a string
  and reverse-mapping it to a lang key. That approach produced a long
  tail of edge-case bugs - dynamic slugs containing `/`, optional
  segments, query strings, encoding
  ([#928](https://github.com/mcamara/laravel-localization/issues/928),
  [#933](https://github.com/mcamara/laravel-localization/issues/933),
  [#924](https://github.com/mcamara/laravel-localization/issues/924),
  [#885](https://github.com/mcamara/laravel-localization/issues/885)).
  Here each locale variant is a named static route, so switching is a
  name lookup against the router's already-extracted parameters - no
  URI re-parsing involved.
- **Livewire works out of the box.** Render a Livewire 3 or 4
  component from a localized route - subsequent updates run under the
  same locale via Livewire's built-in `SupportLocales` hook. No
  `setUpdateRoute()` recipe, no URL rewriting, no JS fetch
  interception. Both majors are covered by an integration test matrix
  in CI. See [Livewire](/livewire). 
- **Multi-tenant ready.** Per-request runtime overrides for the active
  locales (`Localizer::setActiveLocales()`) and the default locale
  (`Localizer::setActiveDefaultLocale()`) let each tenant ship a
  different subset of locales and a different unprefixed default
  without touching global config or Laravel's translation fallback.
  The original had no concept of per-request locale scoping. See
  [Multitenancy](/multitenancy).
- **Modular architecture friendly.** Each module can call
  `Route::localize()` / `Route::translate()` from its own
  `RouteServiceProvider`; static registration means `route:cache` works
  no matter how many modules contribute localized routes. The original
  required each module to wrap its routes in
  `Route::group(['prefix' => LaravelLocalization::setLocale()], …)`,
  which re-ran `setLocale()` once per module per request. Here
  `SetLocale` runs once via the middleware kernel, regardless of how
  many modules participate.
- **First-class Ziggy / Wayfinder support.** Adapter classes ship in
  the package (`LocalizerZiggyV1/V2`, `LocalizerBladeRouteGeneratorV1`,
  `localizedRoute()` helper); Inertia setups previously had to roll
  their own.
- **Simpler middleware setup.** The original chained five middleware
  on every localized route group (`localize`, `localizationRedirect`,
  `localeSessionRedirect`, `localeCookieRedirect`, `localeViewPath`)
  with subtle ordering rules that silently broke locale persistence
  if you got them wrong. Here the localization group itself stays
  bare - `Route::localize()` / `Route::translate()` carry no
  package-specific middleware. `SetLocale` + `RedirectLocale` register
  once in the `web` group; session and cookie persistence move to
  config flags (`persist_locale.session` / `persist_locale.cookie`).
- **`route:list` shows every variant.** All locale variants appear as
  separate rows in one table. The original only listed the routes for
  the current process's locale - a misleading half-view. 
- **No locale leakage onto unlocalized routes.** `SetLocale` /
  `RedirectLocale` only act on routes registered through
  `Route::localize()` / `Route::translate()`. The original's
  `'prefix' => LaravelLocalization::setLocale()` mutated
  `App::getLocale()` even on `urlsIgnored` routes, breaking `/admin`,
  `/api/health` and anything else outside the localized group.
- **POST/PUT/DELETE redirects keep their bodies.** `RedirectLocale`
  skips non-safe methods, avoiding the 302→GET browser downgrade that
  silently dropped form bodies in the original.

## 1. Swap the package

```bash
composer remove mcamara/laravel-localization
composer require niels-numbers/laravel-localizer
```

The service provider auto-registers via package discovery.

## 2. Replace the middleware

The old package shipped **five** middleware aliases that you composed
per route group; this package collapses the same surface into **two**.
Drop all of these:

| Old alias | Old class | Replaced by |
|---|---|---|
| `localize` | `LaravelLocalizationRoutes` | `SetLocale` (URL → locale resolution) |
| `localizationRedirect` | `LaravelLocalizationRedirectFilter` | `RedirectLocale` (canonical redirect) |
| `localeSessionRedirect` | `LocaleSessionRedirect` | `persist_locale.session` config + `SetLocale` |
| `localeCookieRedirect` | `LocaleCookieRedirect` | `persist_locale.cookie` config + `SetLocale` |
| `localeViewPath` | `LaravelLocalizationViewPath` | not built in - see [What's not migrated](#whats-not-migrated) |

Add the new package's two middleware to the `web` group:

```php
// Laravel 11+: bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(remove: [
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ]);
    $middleware->web(append: [
        \NielsNumbers\LaravelLocalizer\Middleware\SetLocale::class,
        \NielsNumbers\LaravelLocalizer\Middleware\RedirectLocale::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ]);
})
```

For Laravel 9/10, register both classes in the `web` group in
`app/Http/Kernel.php`.

> **No more middleware-order footguns.** In the old package, session
> and cookie persistence were each their own middleware that you
> chained on every localized group. Getting the order wrong - or
> attaching `localizationRedirect` without `localeSessionRedirect`,
> or vice versa - silently broke locale persistence or caused redirect
> loops. Here, persistence is configured
> (`persist_locale.session` / `persist_locale.cookie`) and handled
> inside `SetLocale`. The only ordering constraint is that `SetLocale`
> must run before `SubstituteBindings`, which the `web` group already
> guarantees.

## 3. Rewrite route definitions

Replace the prefix + middleware wrapper with `Route::localize()`:

```php
// Before
Route::prefix(LaravelLocalization::setLocale())
     ->middleware(['localizationRedirect', 'localeSessionRedirect', 'localizationRedirect', 'localeViewPath'])
     ->group(function () {
         Route::get('/about', AboutController::class)->name('about');
     });

// After
Route::localize(function () {
    Route::get('/about', AboutController::class)->name('about');
});
```

The macro registers **two** static routes per definition (one with the
`{locale}` placeholder, one without) instead of one dynamic prefix
that mutates per request. That's what makes `route:cache` safe - see
[step 6](#6-enable-routecache).

For translated URI paths (`/de/ueber`, `/fr/a-propos`):

```php
// Before
Route::group(['prefix' => LaravelLocalization::setLocale()], function () {
    Route::get(LaravelLocalization::transRoute('routes.about'), AboutController::class)
         ->name('about');
});

// After
use NielsNumbers\LaravelLocalizer\Facades\Localizer;

Route::translate(function () {
    Route::get(Localizer::url('about'), AboutController::class)->name('about');
});
```

The translation file shape is unchanged - keep `lang/{locale}/routes.php`
as it is.

## 4. Replace URL helpers with named routes

The supported path is **named routes + `route()`**. The URL generator
picks the locale-aware variant automatically; you do not have to pass the
locale explicitly. 

```php
// Before
LaravelLocalization::localizeUrl('/users');
LaravelLocalization::getURLFromRouteNameTranslated($locale, 'routes.users');
LaravelLocalization::getLocalizedURL($locale);   // current page in another locale

// After
route('users');                       // current locale
route('users', ['locale' => 'fr']);   // explicit override
Route::localizedUrl('fr');            // current page in another locale (canonical, for hreflang)
Route::localizedSwitcherUrl('fr');    // switcher link (always prefixed)
```

There is no direct replacement for `localizeUrl('/users')` (path-based
lookup). If a route doesn't have a name yet, give it one and use
`route()`. The two `Route::localized…Url()` helpers differ in whether
they emit the prefix for the default locale; the
[Template Helpers](/template-helpers) page explains when to use which.

## 5. Migrate config

The old `config/laravellocalization.php` maps to the new
`config/localizer.php` as follows:

| Old | New | Notes |
|---|---|---|
| `supportedLocales` (keys) | `supported_locales` | Just the codes: `['en', 'de', 'fr']`. The old package's nested `name` / `script` / `native` arrays are not supported here - keep that data in your own list if your switcher renders it. |
| `useAcceptLanguageHeader` | implicit via `BrowserDetector` in `detectors` | Enabled by default. Remove `BrowserDetector::class` from `detectors` to disable. |
| `hideDefaultLocaleInURL` | `hide_default_locale` | Same semantics. |
| `localeSessionRedirect` middleware | `persist_locale.session` | Moved from middleware to config (see step 2). |
| - | `persist_locale.cookie` | New: cookie persistence. On by default. |
| `localesOrder`, `localesMapping` | - | Not supported. Use a custom detector if you need locale aliasing. |
| `defaultLocale` | `config('app.fallback_locale')` in `config/app.php` | This package reads the framework's fallback locale; don't redefine it here. |

Publish the new config and port your values:

```bash
php artisan vendor:publish --provider="NielsNumbers\\LaravelLocalizer\\ServiceProvider" --tag=config
```

```php
// config/localizer.php
return [
    'supported_locales'   => ['en', 'de', 'fr'],   // from old supportedLocales
    'hide_default_locale' => true,                 // from old hideDefaultLocaleInURL
    'persist_locale'      => [
        'session' => true,                         // had localeSessionRedirect? leave true
        'cookie'  => true,
    ],
    // 'detectors' default is fine for most apps
];
```

Then delete `config/laravellocalization.php`.

## 6. Enable `route:cache` (and drop `route:trans:*`)

The old package generated routes dynamically per request, so plain
`php artisan route:cache` either silently broke the app or shipped a
cache for one locale only. The package shipped its own commands as a
workaround:

- `php artisan route:trans:cache` - used in place of `route:cache`
- `php artisan route:trans:clear` - used in place of `route:clear`
- `php artisan route:trans:list {locale}` - `route:list` per locale

None of that is needed here. Use Laravel's built-in commands directly:

```bash
php artisan route:cache
php artisan route:clear
php artisan route:list
```

The two physical routes per definition are static and deterministic.
The locale-aware *selection* between them happens at runtime in the
URL generator, which is unaffected by the route cache. Same for
`Route::translate()` - those URIs are baked in at registration time.

**Action items:**

- Replace `route:trans:cache` / `route:trans:clear` calls in
  deployment scripts, `composer.json` scripts, CI pipelines and
  Forge/Envoyer recipes with the plain `route:cache` / `route:clear`.
- Remove the `LoadsTranslatedCachedRoutes` trait from
  `app/Providers/RouteServiceProvider.php`:

  ```php
  // Delete these two lines:
  // https://github.com/mcamara/laravel-localization/blob/master/CACHING.md
  use \Mcamara\LaravelLocalization\Traits\LoadsTranslatedCachedRoutes;
  ```

  The trait overrode `loadCachedRoutes()` to dispatch to a per-locale
  cache file. With the new package, Laravel's default cache loader is
  the right thing - no override needed.
- `php artisan route:list` shows every variant in one table; both
  `with_locale.about` and `without_locale.about` (or the per-locale
  `translated_de.about` etc.) appear as separate rows. There is no
  per-locale filter - pipe through `grep` if you need one.

## 7. Update Ziggy / JS route helpers

If your app uses Ziggy (or Inertia with Ziggy underneath), the
server-side variant selection that `route()` does in PHP does **not**
happen in JS for free - Ziggy emits all `with_locale.*` /
`without_locale.*` route names verbatim. Install the `LocalizerZiggy`
adapter; it rewrites the manifest before it ships to the client.

See [docs/javascript-route-helpers.md](javascript-route-helpers.md) -
the doc covers both Ziggy variants (`tighten/ziggy` v2+ and
`tightenco/ziggy` v1, which need different bindings) and the
Wayfinder helper.

## What's not migrated

A few features of the old package have no built-in equivalent here.
Most apps don't need them; if yours does, the workaround is usually
straightforward.

- **`localeViewPath` middleware** (per-locale Blade view directories).
  Not built in. Use Laravel's view namespaces or call
  `View::addLocation(...)` from a service provider keyed on
  `App::getLocale()`.
- **`LaravelLocalization::getCurrentLocale()` and friends.** Just use
  `App::getLocale()` / `App::setLocale()` directly - Laravel's own API.
- **Locale aliasing (`localesMapping`).** Implement as a custom
  detector that maps the alias to a supported locale and register it
  in `detectors`. See [Detectors](/detectors).
- **Nested locale metadata** (`name`, `script`, `native`, `regional`).
  Not part of this package's config. Keep that data in your own list
  if your switcher renders it.
- **`getCurrentLocaleDirection()` (RTL/LTR).** Available as
  `Localizer::currentLocaleDirection()` and
  `Localizer::localeDirection($locale)`. Unlike the old package these
  do **not** require per-locale `script` config - common RTL languages
  are detected from defaults, BCP 47 script subtags (`uz-Arab`,
  `pa-Arab`) are honored, and per-locale overrides are available via
  `localizer.locale_directions`. See
  [Locale direction](/template-helpers#locale-direction).
