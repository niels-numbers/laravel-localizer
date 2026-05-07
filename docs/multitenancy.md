# Multitenancy

Three concepts:

- **Supported locales** (`config('localizer.supported_locales')`):
  static union, evaluated at boot. Drives route registration. Cannot
  change per request without breaking `route:cache`.
- **Active locales** (runtime): subset the user can reach in the
  current request. Defaults to supported. Narrow via
  `Localizer::setActiveLocales([...])`.
- **Default locale** (runtime): which locale is unprefixed when
  `hide_default_locale` is on. Defaults to
  `config('app.fallback_locale')`. Override per request via
  `Localizer::setActiveDefaultLocale(...)`.

Use case: each tenant exposes a different subset of the globally
supported locales, possibly with a different default. Configure the
union in `supported_locales`, narrow + redefine the default per request.

## Tenant middleware

```php
// app/Http/Middleware/TenantLocales.php
use Closure;
use Illuminate\Http\Request;
use NielsNumbers\LaravelLocalizer\Facades\Localizer;

class TenantLocales
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = $request->tenant(); // your resolver

        Localizer::setActiveLocales($tenant->supported_locales);
        Localizer::setActiveDefaultLocale($tenant->default_locale);

        try {
            return $next($request);
        } finally {
            // Reset for long-running workers (Octane, queue).
            // The Localizer is a singleton; without reset the
            // override leaks into the next request on the worker.
            Localizer::setActiveLocales(null);
            Localizer::setActiveDefaultLocale(null);
        }
    }
}
```

## Middleware order

`TenantLocales` runs **before** `SetLocale` so detection respects the
narrowed subset and the tenant default:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(remove: [
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ]);
    $middleware->web(append: [
        \App\Http\Middleware\TenantLocales::class,
        \NielsNumbers\LaravelLocalizer\Middleware\SetLocale::class,
        \NielsNumbers\LaravelLocalizer\Middleware\RedirectLocale::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ]);
})
```

## Why not `Config::set('app.fallback_locale', ...)`?

Three problems:

1. **Overloaded.** `fallback_locale` is also Laravel's translation
   fallback. Mutating it per tenant changes translation behavior, not
   just URL behavior.
2. **Octane leaks.** The `Config` repository survives across requests.
   Without a reset hook, Tenant A's value bleeds into Tenant B.
3. **Boot-time consumers.** Some package internals read config at
   boot. Mid-request mutations don't reach them.

`setActiveDefaultLocale()` lives on the Localizer singleton
(request-scoped), doesn't touch translation config, and is read at
request time by every consumer that needs it.

## What changes vs. default

- `/fr/about` on a tenant where `fr` isn't active: `SetLocale` ignores
  the prefix and falls back to the resolution chain;
  `RedirectLocale` doesn't strip or add it.
- `Route::localizedSwitcherUrl()` still iterates `supportedLocales()`.
  Filter against `Localizer::activeLocales()` yourself when rendering.
- `route('about')` resolves the same as before. Inactive-locale routes
  still exist physically; the package just won't *route* the user there.
- `hide_default_locale` follows the **active default**. With Tenant B
  (`fr` default), `route('about')` for `fr` returns `/about`;
  `/fr/about` gets 302'd to `/about` by `RedirectLocale`.

## Caveat: `Route::translate()` and per-tenant defaults

> **`Route::translate()` does not work correctly when tenants have
> different default locales.** Use `Route::localize()` instead.

Timing: routes register at **boot**, your `TenantLocales` middleware
runs at **request time**. Boot first, middleware second - so when
`TranslateMacro::register()` decides which locale gets the unprefixed
variant, no override exists yet, and it falls back to
`config('app.fallback_locale')`.

```
1. BOOT (once per worker)
   Route::translate(...) -> reads config('app.fallback_locale') = 'en'
   registers:
     translated_en.about  -> /en/about
     translated_de.about  -> /de/ueber
     without_locale.about -> /about        ← baked against 'en'

2. REQUEST (Tenant B with 'de' default)
   TenantLocales::handle()
   -> Localizer::setActiveDefaultLocale('de')   ← too late!
   -> routes are fixed; unprefixed = /about, not /ueber
```

`Route::localize()` doesn't have this problem: every locale shares the
same URI; only the prefix differs, stripped at URL-generation time.

If you really need translated paths *and* per-tenant defaults, register
per-locale `without_locale.*` routes yourself and extend `UrlGenerator`
to pick between them - see `TranslateMacro` for a starting point.

## API summary

| Method | Purpose |
|---|---|
| `Localizer::supportedLocales()` | Static union (boot-time). |
| `Localizer::activeLocales()` | Runtime subset; defaults to supported. |
| `Localizer::isSupported($locale)` | Membership in supported. |
| `Localizer::isActive($locale)` | Membership in active. |
| `Localizer::setActiveLocales($array\|null)` | Narrow (or reset with `null`). |
| `Localizer::defaultLocale()` | Runtime default; defaults to `app.fallback_locale`. |
| `Localizer::setActiveDefaultLocale($string\|null)` | Override (or reset with `null`). |
