# Migrating from `mcamara/laravel-localization`

This guide walks through swapping `mcamara/laravel-localization` for
`niels-numbers/laravel-localizer` on an existing app. The two packages
solve the same problem - locale-prefixed routes plus auto-detection -
but the wiring differs.

## Why migrate

The original package was the first to tackle locale-aware routing in
Laravel and powered multilingual apps for a decade. Several issues
that piled up over time were not fixable as patches - they were
consequences of generating routes dynamically per request. This rewrite
addresses them at the architecture level:

- **`php artisan route:cache` is now working.** For the original,
  `route:cache` did not work - routes were generated dynamically per
  request, so the cache either silently broke the app or shipped a
  cache for one locale only. The original shipped its own
  `route:trans:cache` command as a custom workaround, which itself
  broke on Laravel 11
  ([mcamara/laravel-localization#927](https://github.com/mcamara/laravel-localization/issues/927)).
  This package registers two static routes per definition (one with a
  `{locale}` placeholder, one without), so plain `route:cache` works
  on every supported Laravel version.
- **Higher compatibility with the wider Laravel ecosystem.** Because
  routes are static instead of dynamically generated per request,
  third-party packages that introspect or cache the route table -
  Ziggy, Wayfinder, Telescope, route-list-driven tooling - see every
  variant up front and don't get surprised. Many of the collisions
  reported against the original package were downstream consequences
  of the dynamic-routing model; switching to static routes removes
  the root cause rather than patching the symptoms.
- **`php artisan route:list` shows every variant.** In the original,
  `route:list` only listed the routes registered for the current
  process's locale - a misleading partial view that hid half your
  app's URLs. Here, `with_locale.{name}` and `without_locale.{name}`
  (and per-locale `translated_{locale}.{name}` for `Route::translate()`)
  appear as separate rows in one table.
- **No more locale leakage onto unlocalized routes.** The original's
  recommended `'prefix' => LaravelLocalization::setLocale()` had a
  side effect: hitting *any* route - even one in `urlsIgnored` -
  would call `App::setLocale()`. That broke `/admin`, `/api/health`,
  and anything else outside the localized group. Here, both `SetLocale`
  and `RedirectLocale` only act on routes registered through
  `Route::localize()` / `Route::translate()` (detected via the
  `locale_type` action attribute the macros set); plain routes in the
  same `web` group pass through with zero side effects.
- **POST/PUT/DELETE redirects no longer lose form bodies.**
  `RedirectLocale` skips non-safe methods to avoid the 302→GET
  browser downgrade that silently dropped request bodies in the
  original.
- **Five middlewares collapsed into two.** `localize`,
  `localizationRedirect`, `localeSessionRedirect`, `localeCookieRedirect`,
  `localeViewPath` had subtle ordering rules and silently broke locale
  persistence if you got them wrong. Here: `SetLocale` + `RedirectLocale`,
  with persistence as config flags (`persist_locale.session` /
  `persist_locale.cookie`).
- **First-class adapters for Ziggy and Wayfinder.** The original had
  no client-side story; Inertia setups had to roll their own.

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
    $middleware->web(append: [
        \NielsNumbers\LaravelLocalizer\Middleware\SetLocale::class,
        \NielsNumbers\LaravelLocalizer\Middleware\RedirectLocale::class,
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
[Template Helpers section](../README.md#template-helpers) explains
when to use which.

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
  in `detectors`. See [Detectors](../README.md#detectors).
- **Nested locale metadata** (`name`, `script`, `native`, `regional`).
  Not part of this package's config. Keep that data in your own list
  if your switcher renders it.
