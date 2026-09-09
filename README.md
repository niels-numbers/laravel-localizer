# Laravel Localizer

[![Tests](https://github.com/niels-numbers/laravel-localizer/actions/workflows/tests.yml/badge.svg)](https://github.com/niels-numbers/laravel-localizer/actions/workflows/tests.yml)
![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-9%20%7C%2010%20%7C%2011%20%7C%2012%20%7C%2013-blue?logo=laravel&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)

Laravel can already prefix routes with `/{locale}`. What it does not
do: detect a visitor's language, redirect them there, remember the
choice, and keep `route('about')` returning the right URL. This package
adds exactly that.

**Documentation: [localizer.adam-nielsen.de](https://localizer.adam-nielsen.de)**

> Successor to [`mcamara/laravel-localization`](https://github.com/mcamara/laravel-localization).
> Coming from there? See the [migration guide](https://localizer.adam-nielsen.de/migrating-from-laravel-localization).

## What you get

Wrap your routes once:

```php
Route::localize(function () {
    Route::get('/about', AboutController::class)->name('about');
});
```

The page is now reachable in every configured language:

| URL          | Language                        |
|--------------|---------------------------------|
| `/about`     | `en` (default, prefix hidden)   |
| `/de/about`  | `de`                            |
| `/fr/about`  | `fr`                            |

The default language has no prefix because `hide_default_locale` is on
by default. Turn it off and every URL carries a prefix: `/about` then
redirects to `/en/about`.

`route('about')` keeps working as before and returns the URL for the
current language: in Blade, controllers, jobs, mails and, with the
[JavaScript route helpers](https://localizer.adam-nielsen.de/javascript-route-helpers),
in your Vue or React frontend.

Want `/de/ueber` and `/fr/a-propos` instead of `/de/about`? See
[Translated URL Paths](https://localizer.adam-nielsen.de/translated-url-paths).

## What happens on a visit

1. **First visit**: someone opens `/about`. The package reads their
   browser language (or your own detectors) and redirects them, for
   example to `/de/about`. English visitors stay on `/about`.
2. **From then on the URL decides**: `/de/about` shows German and
   remembers it in session and cookie. A link to plain `/about` sends
   that visitor back to `/de/about`.
3. **One canonical URL per page**: the default language has no prefix,
   so `/en/about` redirects to `/about`.

A language switcher is one helper call per language:
`Route::localizedSwitcherUrl('fr')`. See
[Language Switcher](https://localizer.adam-nielsen.de/language-switcher).

## Install

```bash
composer require niels-numbers/laravel-localizer
```

Then set your locales, register two middlewares and wrap your routes.
The [setup guide](https://localizer.adam-nielsen.de/installation) walks
through the three steps.

## Coming from `mcamara/laravel-localization`?

Same job, different wiring. Every localized route is a plain, static
Laravel route, so `php artisan route:cache` works, translated routes
switch reliably and Livewire works out of the box. The
[migration guide](https://localizer.adam-nielsen.de/migrating-from-laravel-localization)
covers the differences step by step.

Not sure you need this package at all? See
[when to use it and how it compares](https://localizer.adam-nielsen.de/comparison)
to `mcamara/laravel-localization`, `codezero-be/laravel-localized-routes`
and `spatie/laravel-translatable`.

## License & credits

MIT licensed. Created by Adam Nielsen, building on prior work by
[@mcamara](https://github.com/mcamara) (original
`laravel-localization`),
[@codezero-be](https://github.com/codezero-be) (deprecated
`laravel-localized-routes`, whose static-route ideas inspired this
rewrite) and
[@jordyvanderhaegen](https://github.com/jordyvanderhaegen) (current
maintainer of the original, whose
[issue #921](https://github.com/mcamara/laravel-localization/issues/921)
motivated this package).
