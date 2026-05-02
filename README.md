# Laravel Localizer

[![Tests](https://github.com/niels-numbers/laravel-localizer/actions/workflows/tests.yml/badge.svg)](https://github.com/niels-numbers/laravel-localizer/actions/workflows/tests.yml)
![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-9%20%7C%2010%20%7C%2011%20%7C%2012%20%7C%2013-blue?logo=laravel&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)

> **Official successor to [`mcamara/laravel-localization`](https://github.com/mcamara/laravel-localization).**
> The original package is marked abandoned in Composer with this
> package set as the replacement
> ([mcamara/laravel-localization#955](https://github.com/mcamara/laravel-localization/pull/955)).
> Migrating? See [docs/migrating-from-laravel-localization.md](docs/migrating-from-laravel-localization.md)
> - it covers the swap step by step and lists the long-standing
> issues this rewrite addresses.

Detect a visitor's preferred language and serve the right localized URL.

Laravel ships with [localization](https://laravel.com/docs/localization)
features. But if you want a truly multi-language
app, the *routing* layer is missing. This package fills that gap.

Concretely: imagine you have routes like `/{locale}/about`. What should
happen when a visitor lands on `/about` without a locale prefix? This
package answers that:

1. **First visit to `/about`**: detect the visitor's language (browser
   `Accept-Language` or fallback), redirect to e.g. `/fr/about`, and
   persist the locale in session and cookie.
2. **Subsequent visits to `/about`**: read the locale from session/cookie
   and redirect to the matching `/fr/about`.
3. **In your code**: `route('about')` always resolves to the correct
   locale variant - no redirect roundtrip, no manual locale parameter.
4. **Optionally hide the default locale**: `/en/about` becomes `/about`
   for the default language; rules 1 and 2 still apply.

As an add-on, this package also supports fully translated URI paths
(`/de/ueber`, `/fr/a-propos`). It is fully compatible with
`php artisan route:cache`, with adapters available for Ziggy and
Wayfinder.

## Example

```php
Route::localize(function () {
    Route::get('/about', AboutController::class)->name('about');
});
```

This single definition produces:

- `/about`: default locale (e.g. English), prefix hidden
- `/de/about`: German
- `/fr/about`: French (and so on for every configured locale)

Under the hood, two static routes are registered per definition. `php artisan route:list` shows:

```
  GET|HEAD  about ............................. without_locale.about › AboutController
  GET|HEAD  {locale}/about ........................ with_locale.about › AboutController
```

In your application code, `route('about')` always picks the right
variant for the current request, both server-side and (with the
[JS adapter](#javascript-route-helpers)) client-side.

> You don't pass the locale to the `route` helper:
> the `SetLocale` middleware sets it as a default URL
> parameter via Laravel's
> [`URL::defaults()`](https://laravel.com/docs/urls#default-values),
> so Laravel fills the `{locale}` placeholder automatically.

When a visitor first lands on `example.com`, the package detects their
browser language and redirects to the matching locale. The choice is
persisted in the session and a cookie for follow-up requests.

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Defining Routes](#defining-routes)
- [Template Helpers](#template-helpers)
- [JavaScript Route Helpers](#javascript-route-helpers)
- [Language Switcher](#language-switcher)
- [Detectors](#detectors)
- [Redirects](#redirects)
- [Locale in Jobs, Mailables and Notifications](#locale-in-jobs-mailables-and-notifications)
- [Translated URL Paths](#translated-url-paths)
- [Caveats and Recipes](#caveats-and-recipes)
- [When to use this package](#when-to-use-this-package)
- [Restricting Active Locales (Multitenancy)](#restricting-active-locales-multitenancy)
- [Comparison to other packages](#comparison-to-other-packages)
- [Background](#background)
- [Testing](#testing)
- [Credits](#credits)

## Requirements

- **PHP** 8.2 / 8.3 / 8.4
- **Laravel** 9, 10, 11, 12, or 13

## Installation

```bash
composer require niels-numbers/laravel-localizer
```

The service provider auto-registers via package discovery. Three steps
to finish setup:

**1. Set your supported locales.** Make sure your default is set in
`config/app.php`:

```php
'fallback_locale' => 'en',
```

Then publish and edit the package config:

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

**2. Register the middleware.**

For Laravel 11+, in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \NielsNumbers\LaravelLocalizer\Middleware\SetLocale::class,
        \NielsNumbers\LaravelLocalizer\Middleware\RedirectLocale::class,
    ]);
})
```

For Laravel 9 / 10, add both classes to the `web` group in
`app/Http/Kernel.php`.

> **Safe to mix with unlocalized routes.** Both middlewares only act on
> routes registered through `Route::localize()` / `Route::translate()`
> (detected via a `locale_type` action attribute the macros set). Plain
> routes in the same `web` group — `/admin`, `/api/health`, anything
> outside the macros — pass through untouched: no redirect, no
> `App::setLocale()` side effect. See [Mixing localized and unlocalized
> routes](#mixing-localized-and-unlocalized-routes).

**3. Wrap your routes** in `Route::localize()`. See [Defining Routes](#defining-routes).

## Configuration

Publish the config file with:

```bash
php artisan vendor:publish --provider="NielsNumbers\\LaravelLocalizer\\ServiceProvider" --tag=config
```

This creates `config/localizer.php`.

| Key | Type | Default | Description |
|-----|------|----------|--------------|
| `supported_locales` | `array` | `[]` | List of all available locales. Example: `['en', 'de']`. |
| `hide_default_locale` | `bool` | `true` | If `true`, URLs using the **default (fallback)** locale will be redirected to the version **without** a locale prefix. Example: `/en/about` → `/about`. |
| `persist_locale.session` | `bool` | `true` | If `true`, the detected locale is stored in the session. |
| `persist_locale.cookie` | `bool` | `true` | If `true`, the detected locale is stored in a browser cookie. |
| `detectors` | `array` | `[UserDetector::class, BrowserDetector::class]` | Ordered list of classes used to detect a user's locale when no locale is found in the URL, session, or cookie. See [Detectors](#detectors). |
| `redirect_enabled` | `bool` | `true` | Enables or disables automatic redirects between prefixed and unprefixed routes. See [Redirects](#redirects). |

> The package's reference for the **default locale** is
> `config('app.fallback_locale')` (in `config/app.php`), not a localizer
> config of its own. It's the base for `hide_default_locale` and the
> fallback language for missing translations.

## Defining Routes

Wrap your routes with `Route::localize()` to register them in both their
prefixed and unprefixed form:

```php
Route::localize(function () {
    Route::get('/about', AboutController::class)->name('about');
});
```

This generates `/about`, `/de/about`, `/fr/about` (etc.) from a single
definition. In your application code, keep using `route('about')`; the
package picks the right variant based on the current locale.

To attach middleware, prefixes, or other route attributes, define them
**inside** the `Route::localize()` closure as you would in any other group -
`Route::localize()` is itself a group, so nested groups compose the way
Laravel groups normally compose:

```php
Route::localize(function () {
    Route::get('/about', AboutController::class)->name('about');

    Route::middleware('auth')->prefix('account')->group(function () {
        Route::get('/profile', ProfileController::class)->name('profile');
    });
});
```

> **The closure runs twice**, once per route variant. Keep it side-effect-free:
> no logging, no DB writes, no external calls. Treat it as a pure route
> definition.

> Need per-locale paths like `/about`, `/de/ueber`, `/fr/a-propos` instead
> of just locale prefixes? See [Translated URL Paths](#translated-url-paths).

### URL Generation Is Context-Dependent

`route('about')` resolves to a different URL depending on the current
`App::getLocale()`. The same call inside an HTTP request, a queued job, or a
mailable can yield different results. That's the whole point: you keep using
`route('about')` everywhere and the package picks the right variant.

```php
App::setLocale('en');
route('about'); // → /about      (default locale, hidden via hide_default_locale)

App::setLocale('de');
route('about'); // → /de/about

route('about', ['locale' => 'en']); // → /about (explicit override wins)
```

This is **fully compatible with `php artisan route:cache`**. The cache
serializes the *route definitions* (`with_locale.about` → `/{locale}/about`,
`without_locale.about` → `/about`); those are static and deterministic. The
locale-aware *selection* between them happens at runtime in the URL generator,
which is unaffected by the cache. URL-translated routes built by
[`Route::translate()`](#translated-url-paths) are likewise baked into static
URIs at registration time, so the cache covers them too.

## Template Helpers

Three additional macros are available on the `Route` facade for use in
controllers, Blade templates, and middleware.

### `Route::localizedUrl($locale, $absolute = true)`

Returns the **current** request's URL in another locale. Primary use is
`<link rel="alternate" hreflang="...">` tags, canonical URLs and
sitemaps:

```blade
@foreach (config('localizer.supported_locales') as $locale)
    <link rel="alternate"
          hreflang="{{ $locale }}"
          href="{{ Route::localizedUrl($locale) }}" />
@endforeach
```

The returned URL is the **canonical** form. Switching to the default locale
with `hide_default_locale` enabled yields `/about` directly, not
`/en/about` followed by a 301. Suitable for hreflang attributes that crawlers
read literally. For an **in-page language switcher** use the sibling helper
`Route::localizedSwitcherUrl($locale)` - it always emits the prefixed form,
which is what carries the locale signal across the click. See
[Language Switcher](#language-switcher).

> **Canonical (`/about`) vs. always-prefixed (`/en/about`) for hreflang:**
> Google's official guidance is to point hreflang at canonical URLs, which
> is what `localizedUrl()` returns - `/about` for the hidden default
> locale, `/de/about` etc. for others. This is the normal recommendation.
>
> However, if a visitor with a non-default browser locale (or a stale
> session/cookie) hits `/about`, `RedirectLocale` will 302 them to
> `/de/about`. If you'd rather avoid any redirect roundtrip - at the cost
> of having two URLs that resolve to English content (`/en/about` and
> `/about`) - use `Route::localizedSwitcherUrl($locale)` in your hreflang
> tags instead. That always emits the prefixed form, even for the default
> locale.

| Current route | Behavior |
|---|---|
| Named (recommended) | Resolved through `route()`; works for both macros. |
| Unnamed `Route::localize()` | Falls back to a URI prefix swap on the request path. |
| Unnamed `Route::translate()` | Throws `LogicException`; the translated URI can't be reversed. Add `->name()`. |
| Called outside a request | Throws `LogicException`. |

### `Route::hasLocalized($name)`

Returns `true` if a route with the given name was registered through
`Route::localize()` or `Route::translate()`:

```blade
@if (Route::hasLocalized('about'))
    <a href="{{ route('about') }}">{{ __('About') }}</a>
@endif
```

Checks `with_locale.{name}`, `without_locale.{name}` and
`translated_{$locale}.{name}` for every supported locale.

### `Route::isLocalized()`

Returns `true` if the **current** request was matched to a localizer-managed
route. Convenient for showing a language switcher only on localized pages:

```blade
@if (Route::isLocalized())
    @include('partials.language-switcher')
@endif
```

## JavaScript Route Helpers

Client-side URL builders like [Ziggy](https://github.com/tighten/ziggy)
and [Laravel Wayfinder](https://github.com/laravel/wayfinder) don't go
through this package's `UrlGenerator` override; the locale-aware variant
selection that `route('about')` does on the server doesn't happen in JS
automatically. With a small adapter per stack you get the same DX as on
the server - same applies for **Inertia.js**, which bundles one of these
two as its route helper.

See [docs/javascript-route-helpers.md](docs/javascript-route-helpers.md)
for the Ziggy adapter, the Wayfinder helper, and the cross-locale / SEO
notes.

## Language Switcher

Use a single switcher component anywhere in your layout. It picks the
right URLs from **`Route::localizedSwitcherUrl()`** so each link points
to the **current page** in the target locale. Clicking a link triggers a
normal navigation: the URL carries the new locale, `SetLocale` reads it
on the next request and persists it to session/cookie.

> **Why a different helper than `localizedUrl()`?** `localizedUrl()`
> returns the **canonical** URL (no `/en` prefix when English is the
> hidden default) - correct for `<link rel="alternate">` and sitemaps.
> But a switcher link to the default locale needs the prefix: it's the
> only way the URL itself can tell `SetLocale` which language to switch
> to. Without it, a stale session locale would win and `RedirectLocale`
> would bounce the visitor back. `localizedSwitcherUrl()` always emits
> the prefixed form; `RedirectLocale` then strips it on the follow-up
> request, so the browser ends up on the canonical URL anyway - one
> invisible 302 hop.

### Blade

Define once as a component, include anywhere:

```blade
{{-- resources/views/components/language-switcher.blade.php --}}
@foreach (config('localizer.supported_locales') as $locale)
    <a href="{{ Route::localizedSwitcherUrl($locale) }}"
       @class(['active' => app()->getLocale() === $locale])>
        {{ strtoupper($locale) }}
    </a>
@endforeach
```

```blade
<x-language-switcher />
```

### Inertia (Vue / React)

The Inertia bridge (Ziggy or Wayfinder underneath) doesn't see
`Route::localizedUrl()` directly. Render the per-locale URLs
server-side and ship them as shared props:

```php
// app/Http/Middleware/HandleInertiaRequests.php
public function share(Request $request): array
{
    return array_merge(parent::share($request), [
        'locale'        => app()->getLocale(),
        'localizedUrls' => fn () => collect(config('localizer.supported_locales'))
            ->mapWithKeys(fn ($l) => [$l => Route::localizedSwitcherUrl($l)])
            ->all(),
    ]);
}
```

Then build a SPA component (Vue example; React works analogously):

```vue
<!-- resources/js/Components/LanguageSwitcher.vue -->
<script setup>
import { usePage } from '@inertiajs/vue3';
const { localizedUrls, locale } = usePage().props;
</script>

<template>
  <a v-for="(url, code) in localizedUrls" :key="code"
     :href="url" :class="{ active: code === locale }">
    {{ code.toUpperCase() }}
  </a>
</template>
```

A plain `<a>` triggers a full-page reload, which is typically what you
want when switching languages: the HTML `lang` attribute, shared props
and any cached translations all need to refresh.

> **SPA language switch via `<Link>`** has a few extra moving parts (Ziggy as
> a shared prop, `route()` reactive to `usePage()`, `<html lang>`
> updates, prefixed switcher URLs). See
> [docs/inertia-spa-language-switch.md](docs/inertia-spa-language-switch.md)
> for a working sketch - marked **experimental**, not yet verified
> end-to-end. Full reload remains the recommended default.

### Caveats

For routes with per-locale model bindings (translated slugs), some
links may build URLs that 404 on follow. Render switcher items
conditionally or add a fallback in `resolveRouteBinding()`.

## Detectors

### Locale Resolution Order

`SetLocale` walks through the following sources, in order, and uses the
first one that yields a supported locale:

1. **URL** - the `{locale}` segment of a `with_locale.*` route
   (`/de/about` → `de`).
2. **Route action** - the `locale` action attribute of the matched route.
   `Route::translate()` registers per-locale routes with literal prefixes
   (`/de/ueber`) and no `{locale}` parameter; the macro stores the locale
   in the route action so `SetLocale` can recover it here.
3. **Session** - the locale stored on a previous request.
4. **Cookie** - the locale persisted client-side.
5. **Detectors** - see below (auth user preference, `Accept-Language`, custom).
6. **`fallback_locale`** from `config/app.php`.

Only the **URL** and **route action** can override an existing session or
cookie. If neither carries a locale signal (the request came in as `/about`
rather than `/de/about` or `/de/ueber`), `SetLocale` keeps using the
session/cookie value -
that's deliberate, so a user who once picked German isn't reset to
English every time they hit an unprefixed link, and `RedirectLocale`
can send them to the prefixed variant.

This is also the reason the language switcher uses
`Route::localizedSwitcherUrl()` rather than `Route::localizedUrl()`:
the switcher always emits the prefixed form (`/en/about`, even for the
hidden default locale), so the URL itself flips the active locale on
click. `RedirectLocale` then strips the prefix on the follow-up to
restore the canonical form. See [Language Switcher](#language-switcher).

### Available Detectors

Detectors run only when steps 1–3 above produced nothing - typically a
first visit with no session and no cookie. Each implements a simple
interface that returns a locale string or `null`.

By default, two detectors are provided:

1. **UserDetector**: reads the locale from the authenticated user model (if available).
2. **BrowserDetector**: detects the preferred language from the `Accept-Language` HTTP header.

You can register your own detectors by adding them to the `detectors` array in the configuration.
They are executed in the order they appear; the first one returning a locale stops the chain.

Example:
```php
'detectors' => [
    \App\Locale\CustomDetector::class,
    \NielsNumbers\LaravelLocalizer\Detectors\UserDetector::class,
    \NielsNumbers\LaravelLocalizer\Detectors\BrowserDetector::class,
],
```

## Redirects

If `redirect_enabled` is set to `true`, the package automatically redirects between localized and non-localized URLs.

### Behavior

1. If `hide_default_locale` is `true` and the current locale is the
   **fallback_locale**, requests to `/en/about` will redirect to `/about`.

   This prevents SEO duplicate content (both `/about` and `/en/about` pointing to the same page).

2. If the current locale is **not** the fallback_locale and the route has **no locale prefix**,
   the request will be redirected to the localized version.
   For example, if the user's session locale is `de` and they open `/about`,
   it will redirect to `/de/about`.

To disable redirects entirely, set:
```php
'redirect_enabled' => false,
```

> **Note:** Disabling redirects is strongly discouraged for normal web apps.
> Without redirects, the application may display the wrong locale or produce duplicate URLs.
> This option is primarily for headless APIs or advanced SPA setups.

## Locale in Jobs, Mailables and Notifications

The `SetLocale` middleware only runs during HTTP requests. Anywhere else
(queued jobs, mailables, notifications, console commands), the application's
locale is whatever the worker process has set globally, typically your
`fallback_locale`.

This affects **everything that reads `App::getLocale()`**, not just URLs:

- `route('about')`: picks the wrong locale variant
- `__('messages.welcome')` / `@lang(...)` / `trans_choice(...)`: wrong language
- Validation messages
- `Carbon` / date formatting (`$date->translatedFormat(...)`, locale-aware diffs)
- Number / currency formatting via `Number::currency()`

Scoping the locale once at the right boundary fixes all of these together.
Laravel handles this for you in two of the three common cases:

### Mailables: automatic via `Mail::to()->locale()`

Pass the recipient's locale to the pending mail; Laravel wraps the entire
build and send in `withLocale($locale, ...)` (see
[laravel/framework#23178](https://github.com/laravel/framework/pull/23178)),
so any `route(...)` call inside your mailable's `build()`/`content()`
resolves with the correct locale.

```php
Mail::to($user)
    ->locale($user->locale)
    ->send(new InvoiceMail($invoice));
```

### Notifications: automatic via the notifiable's preferred locale

If your notifiable model implements `HasLocalePreference`, Laravel's
`NotificationSender` wraps each delivery in `withLocale(...)` for you.

```php
class User extends Model implements HasLocalePreference
{
    public function preferredLocale(): string
    {
        return $this->locale;
    }
}
```

### Plain queued jobs: manual

There is **no** built-in propagation for arbitrary queued jobs (see
[laravel/ideas#394](https://github.com/laravel/ideas/issues/394), closed
without a fix). You have to scope the locale yourself; easiest by adding
the `Localizable` trait to your job and wrapping the locale-sensitive work
in `$this->withLocale(...)`. URLs, translations, validation, dates etc.
inside the closure all see the scoped locale:

```php
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Traits\Localizable;

class SendReminder implements ShouldQueue
{
    use Dispatchable, Queueable, Localizable;

    public function __construct(public User $user) {}

    public function handle(): void
    {
        $this->withLocale($this->user->locale, function () {
            $url     = route('dashboard');
            $subject = __('reminders.subject');
            // …send the reminder using $url and $subject
        });
    }
}
```

If your job's only job is to send a mail or notification, you don't need
this trait; `Mail::to()->locale()` and `HasLocalePreference` already wrap
the relevant code in `withLocale(...)` for you.

## Translated URL Paths

`Route::localize()` keeps the same path in every language. If you need
*truly* localized paths (`/about` vs `/de/ueber` vs `/fr/a-propos`), use
`Route::translate()` together with `Localizer::url()`, which looks
up the URI from your language files:

```php
use NielsNumbers\LaravelLocalizer\Facades\Localizer;

Route::translate(function () {
    Route::get(Localizer::url('about'), AboutController::class)->name('about');
});
```

Define the translations in `lang/{locale}/routes.php`:

```php
// lang/en/routes.php
return [
    'about' => 'about',
];

// lang/de/routes.php
return [
    'about' => 'ueber',
];
```

This registers one route per supported locale (`/en/about`, `/de/ueber`),
plus a no-prefix variant for the default locale when `hide_default_locale`
is on. From your application code, keep using `route('about')`; the
package selects the right URI.

> **Lookup keys must match the full URI.** `routes.about` translates the
> path `/about`. For nested paths use the full path as the key:
> `'blog/post/{slug}' => 'artikel/{slug}'`. The translator does not split
> paths into segments; that would cause unintended hits when the same
> word appears in different contexts (e.g. `routes.about` translating
> `/blog/about/team` → `/blog/ueber/team`).

> **The closure runs N+1 times**: once per supported locale, plus an
> additional time for the `without_locale.` variant when the locale is
> the default and `hide_default_locale` is on. Same side-effect rules
> apply as for `Route::localize()`.

## Caveats and Recipes

### Route names must be unique across both macros

Each route name should be defined **once**. Defining the same name through
both `Route::localize()` and `Route::translate()` causes the second
registration to silently overwrite the first's `without_locale.{name}`
variant (Laravel's route registration is last-write-wins). Pick one macro
per route and stick with it.

### Empty `supported_locales` is a silent no-op

If `config('localizer.supported_locales')` is empty, `Route::translate()`
iterates zero locales, the closure never runs, and no routes get
registered. There is no warning at boot; you'll discover it when
`route('about')` raises `RouteNotFoundException` at request time. Make
sure your config is in place before any service provider that defines
translated routes runs.

### `app.locale` vs `app.fallback_locale`

- `config('app.fallback_locale')` is the package's reference for the
  default locale, used by `hide_default_locale` and as the base
  language for missing translations. Set it in `config/app.php`.
- `config('app.locale')` is updated at runtime by the `SetLocale`
  middleware via `App::setLocale()`. Its initial value in
  `config/app.php` has no lasting effect once the middleware runs.

### Mixing localized and unlocalized routes

You can register routes outside `Route::localize()` / `Route::translate()`
in the same middleware group — they won't be touched. Both `SetLocale`
and `RedirectLocale` look for a `locale_type` action attribute on the
matched route, which the macros set automatically; routes registered
without the macros simply have no `locale_type` and pass through:

```php
$middleware->web(append: [SetLocale::class, RedirectLocale::class]);

// In routes/web.php:
Route::localize(function () {
    Route::get('/about', AboutController::class)->name('about');
});

// Plain unlocalized route — no redirect, no App::setLocale() — works fine.
Route::get('/admin', AdminController::class)->name('admin');
```

Without this, an authenticated user with `session.locale = de` hitting
`/admin` would get a 302 to `/de/admin` (which doesn't exist → 404).
Now `/admin` is reached directly.

### Don't add `$locale` as a controller argument

The `{locale}` URI segment is consumed by `SetLocale` and stripped from
the route parameter bag, so it is **not** passed positionally to your
controller. Write your controllers as if the locale weren't in the URI:

```php
// Route::localize(fn() => Route::get('/users/{country?}', [UsersController::class, 'index']));

// Correct:
public function index(Request $request, ?string $country = null) { … }

// Wrong — $locale will receive the country, not the locale:
public function index(Request $request, string $locale, ?string $country = null) { … }
```

Read the active locale via `App::getLocale()` if you need it.

### Middleware order with translated route bindings

If your localized routes use route model bindings with **per-locale slugs**
(`/de/blog/{post:slug}` resolving a German slug, `/en/blog/{post:slug}` the
English one — see recipe below), `SetLocale` must run **before** Laravel's
`SubstituteBindings` middleware. Otherwise `resolveRouteBinding()` reads
the fallback locale instead of the request's locale.

The recommended setup (`web(append: [SetLocale, RedirectLocale])`) handles
this automatically — both middlewares become part of the `web` group,
which runs before `SubstituteBindings`. If you register them elsewhere
(e.g. as global middleware after the routing pipeline), verify the order.

### Route Model Binding with translated slugs

If your models have per-locale slugs and you want `/de/blog/{post:slug}` to
resolve the German slug while `/en/blog/{post:slug}` resolves the English
one, combine this package with
[spatie/laravel-translatable](https://github.com/spatie/laravel-translatable)
and override `resolveRouteBinding()`:

```php
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Post extends Model
{
    use HasTranslations;

    public $translatable = ['slug'];

    public function resolveRouteBinding($value, $field = null)
    {
        $field = $field ?? $this->getRouteKeyName();

        if ($field === 'slug') {
            return $this->where("slug->" . app()->getLocale(), $value)->firstOrFail();
        }

        return parent::resolveRouteBinding($value, $field);
    }
}
```

Reading `app()->getLocale()` here is reliable: route model binding runs
after the `SetLocale` middleware, so the recipient's locale is already in
place.

### Closures in `Route::translate()` / `Route::localize()` must be pure

Already mentioned in [Defining Routes](#defining-routes), repeated here
because it's the most common surprise:

- `Route::localize()`: closure runs **twice** (one prefixed, one
  unprefixed variant).
- `Route::translate()`: closure runs **N+1 times** (one per supported
  locale, plus once for `without_locale.` when the locale is the default
  and `hide_default_locale` is on).

Side effects inside the closure (logging, DB writes, third-party API
calls) will execute that many times. Treat it as a pure route definition.

## When to use this package

Use this package if you want:

- automatic locale detection from the request (e.g. from the browser)
- automatic redirects to localized routes
- the option to hide the default locale in the URL
- fully translatable routes (e.g. `/en/humans`, `/de/menschen`)

You **don't** need it if you're fine with only:

- `example.com/de/blog`
- `example.com/en/blog`

and don't need `example.com/blog` or locale detection from the browser.

## Restricting Active Locales (Multitenancy)

Two distinct concepts:

- **Supported locales** (`config('localizer.supported_locales')`): the
  static union, evaluated at boot time. Drives route registration -
  every locale here gets a registered route variant. **Cannot change
  per request** without breaking `route:cache` compatibility.
- **Active locales** (runtime): the subset the user is allowed to reach
  in the **current request**. Defaults to the supported set. Can be
  narrowed at runtime via `Localizer::setActiveLocales([...])`.

The classic use case: in a multi-tenant app, each tenant exposes a
different subset of the globally supported locales. Tenant A allows
`en + de`, Tenant B allows `en + fr + es`. Configure the union of both
in `supported_locales`, then narrow per request in middleware.

### Tenant middleware example

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

        try {
            return $next($request);
        } finally {
            // Reset for long-running workers (Octane, queue workers).
            // The Localizer is a container singleton; without reset
            // the override leaks into the next request on the same
            // worker process.
            Localizer::setActiveLocales(null);
        }
    }
}
```

### Middleware order

`TenantLocales` must run **before** `SetLocale` so that `SetLocale`
validates incoming locale candidates against the narrowed subset:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \App\Http\Middleware\TenantLocales::class,
        \NielsNumbers\LaravelLocalizer\Middleware\SetLocale::class,
        \NielsNumbers\LaravelLocalizer\Middleware\RedirectLocale::class,
    ]);
})
```

### What changes vs. the default behavior

- A request to a route for an inactive-but-supported locale (e.g. `/fr/about`
  on Tenant A) is treated as if the prefix isn't a locale at all -
  `SetLocale` falls back to the resolution chain (session → cookie →
  detectors → fallback_locale), and `RedirectLocale` doesn't strip or
  add the inactive prefix.
- `Route::localizedSwitcherUrl()` and friends still iterate
  `supportedLocales()`. If you build a switcher, filter against
  `Localizer::activeLocales()` yourself when rendering.
- `route('about')` resolves the same as before - the underlying
  routes for inactive locales still exist physically; the package just
  won't *route* the user there via locale detection.

### API summary

| Method | Purpose |
|---|---|
| `Localizer::supportedLocales()` | Static union from config (boot-time). |
| `Localizer::activeLocales()` | Runtime subset; defaults to supported. |
| `Localizer::isSupported($locale)` | Membership in supported. |
| `Localizer::isActive($locale)` | Membership in active. |
| `Localizer::setActiveLocales($array\|null)` | Narrow (or reset with `null`). | 

## Comparison to other packages

- **[mcamara/laravel-localization](https://github.com/mcamara/laravel-localization) (deprecated)**
  This package is the official successor to *laravel-localization*,
  which is no longer maintained — `mcamara/laravel-localization` is
  marked abandoned in Composer with `niels-numbers/laravel-localizer`
  set as the replacement
  ([mcamara/laravel-localization#955](https://github.com/mcamara/laravel-localization/pull/955)). The original package was the first to tackle
  the routing problem; it generated routes dynamically at runtime,
  making it incompatible with `php artisan route:cache` and several
  Laravel packages. In contrast, this package registers **two static
  routes** per definition (one with a `{locale}` placeholder and one
  without), making it fully cache-safe and compatible with most modern
  Laravel packages. See
  [docs/migrating-from-laravel-localization.md](docs/migrating-from-laravel-localization.md)
  for a step-by-step migration guide and the full list of long-standing
  issues this rewrite addresses.

- **[codezero-be/laravel-localized-routes](https://github.com/codezero-be/laravel-localized-routes) (deprecated)**
  An alternative to *laravel-localization*, using a **route-per-locale**
  approach (N× routes, one per language). While that package is no
  longer maintained, many of its design ideas influenced this one. Here,
  only **two routes** per definition are created, striking a balance
  between performance, maintainability, and flexibility.

- **[spatie/laravel-translatable](https://github.com/spatie/laravel-translatable)**
  This package serves a different purpose: translating **Eloquent model
  fields**, not routes. It works perfectly alongside this package if you
  want translatable slugs.

## Background

This package is the maintained continuation of [mcamara/laravel-localization](https://github.com/mcamara/laravel-localization).
I (Adam Nielsen) was a collaborator on the original package, and since
@mcamara has moved on from Laravel, I am now maintaining the route
localization package. The original package from mcamara has a very long
legacy.

The [original package](https://github.com/mcamara/laravel-localization)
generated **dynamic routes**, which led to cache and compatibility
issues. [laravel-localized-routes](https://github.com/codezero-be/laravel-localized-routes)
solved this by generating **static routes for each locale** (N× per
definition).

This package takes a **middle path**: each route is registered **twice**,
once with a `{locale}` placeholder, and once without. This avoids
dynamic routing issues while keeping the number of routes manageable.

## Testing

This package includes a Docker setup for consistent testing across environments.

### Prerequisites
- Docker
- Docker Compose
- GNU Make (optional, but recommended)

### Usage with Make

The following will first build the docker image,
then install dependencies via composer and then run phpunit.

```bash
make build    # Build the Docker image
make install  # Install Composer dependencies inside the container
make test     # Run PHPUnit tests (tests are in /tests, using Orchestra Testbench)
```

### Usage without Make

If you don't have `make`, you can run the commands manually:

```bash
docker compose build
UID=$(id -u) GID=$(id -g) docker compose run --rm test composer install
UID=$(id -u) GID=$(id -g) docker compose run --rm test vendor/bin/phpunit
```

## Credits

- [@mcamara](https://github.com/mcamara): original creator of [laravel-localization](https://github.com/mcamara/laravel-localization).
- [@codezero-be](https://github.com/codezero-be): developed a static route-per-locale approach
  (e.g. `en.index`, `de.index`, `es.index`). While this package follows a different routing strategy
  (two routes per definition: one with `{locale}` and one without), many classes and much of the
  implementation style are adapted from [laravel-localized-routes](https://github.com/codezero-be/laravel-localized-routes).
- [@jordyvanderhaegen](https://github.com/jordyvanderhaegen): co-maintainer of
  [laravel-localization](https://github.com/mcamara/laravel-localization);
  his issue [mcamara/laravel-localization#921](https://github.com/mcamara/laravel-localization/issues/921)
  was the motivation for writing this package.

Since [@codezero-be](https://github.com/codezero-be) is no longer with us,
I want to acknowledge his great work and influence on this package.
Many of his ideas live on here, and I hope this helps to keep his contributions
useful to the Laravel community for years to come.
