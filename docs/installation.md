# Installation

## Requirements

- **PHP** 8.2 / 8.3 / 8.4
- **Laravel** 9, 10, 11, 12, or 13

## Install

```bash
composer require niels-numbers/laravel-localizer
```

The service provider auto-registers. Three steps to finish setup.

## 1. Set your supported locales

Make sure your default is set in `config/app.php`:

```php
'fallback_locale' => 'en',
```

Publish the package config:

```bash
php artisan vendor:publish --provider="NielsNumbers\\LaravelLocalizer\\ServiceProvider" --tag=config
```

```php
// config/localizer.php
return [
    'supported_locales' => ['en', 'de', 'fr'],
    // ...
];
```

## 2. Register the middleware

**Laravel 11+** (`bootstrap/app.php`):

```php
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

`SetLocale` has to run after `StartSession` (so session-based locale
detection works) and before `SubstituteBindings` (so translated route
bindings like `{post:slug}` resolve against the correct locale). See
[Middleware order with translated route bindings](/caveats-and-recipes#middleware-order-with-translated-route-bindings)
for the full explanation.

::: tip Other middleware that depends on the locale
For every middleware that requires the correct locale (e.g.
`HandleInertiaRequests` if you share locale-aware props, or any custom
middleware reading `App::getLocale()`), append it in the same call
after `SetLocale`:

```php
$middleware->web(append: [
    \NielsNumbers\LaravelLocalizer\Middleware\SetLocale::class,
    \NielsNumbers\LaravelLocalizer\Middleware\RedirectLocale::class,
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
    \App\Http\Middleware\HandleInertiaRequests::class,
]);
```
:::

**Laravel 9 / 10**: add both middlewares to the `web` group in
`app/Http/Kernel.php`, after `StartSession` but before
`SubstituteBindings`.

::: tip Mixing localized and unlocalized routes is safe
Both middlewares only act on routes registered through
`Route::localize()` / `Route::translate()`. Plain routes (`/admin`,
`/api/health`) pass through untouched. See
[Caveats](/caveats-and-recipes#mixing-localized-and-unlocalized-routes).
:::

## 3. Wrap your routes

```php
Route::localize(function () {
    Route::get('/about', AboutController::class)->name('about');
});
```

Produces:

- `/about` (default locale, prefix hidden)
- `/de/about`, `/fr/about`, ...

`php artisan route:list` shows both as static routes:

```
GET|HEAD  about ............... without_locale.about › AboutController
GET|HEAD  {locale}/about .......... with_locale.about › AboutController
```

In your application code, `route('about')` always picks the right
variant for the current request, both server-side and (with the
[JS adapter](/javascript-route-helpers)) client-side.

::: info You don't pass the locale to the `route` helper
The `SetLocale` middleware sets it as a default URL parameter via
Laravel's [`URL::defaults()`](https://laravel.com/docs/urls#default-values),
so Laravel fills the `{locale}` placeholder automatically.
:::
