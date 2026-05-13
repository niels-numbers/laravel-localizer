# Laravel Localizer

[![Tests](https://github.com/niels-numbers/laravel-localizer/actions/workflows/tests.yml/badge.svg)](https://github.com/niels-numbers/laravel-localizer/actions/workflows/tests.yml)
![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-9%20%7C%2010%20%7C%2011%20%7C%2012%20%7C%2013-blue?logo=laravel&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)

> Successor to [`mcamara/laravel-localization`](https://github.com/mcamara/laravel-localization). Static routes, `route:cache` ready.

Locale-aware routing for Laravel: auto-detect, auto-redirect, and resolve `route()` per language.

**Documentation: [localizer.adam-nielsen.de](https://localizer.adam-nielsen.de)**

## Example

```php
Route::localize(function () {
    Route::get('/about', AboutController::class)->name('about');
});
```

Produces:

- `/about` - this endpoint carries the package's core magic: auto-detection, redirect, or default locale (see below)
- `/de/about`, `/fr/about`, ... for every other configured locale

Every route is registered **twice** as a static route:

```
GET|HEAD  about ............... without_locale.about › AboutController
GET|HEAD  {locale}/about .......... with_locale.about › AboutController
```

In your application code, keep using `route('about')`; the package
picks the right variant based on the current locale.

How `/about` resolves at request time:

1. **First visit**: the package reads the `Accept-Language` header
   (or your own detector chain) and redirects to the matching
   localized URL.
2. **Subsequent visits**: an explicit URL prefix always wins.
   Without a URL signal, the locale is taken from the session and
   cookie. The user is redirected to the prefixed variant unless
   their locale matches the default and `hide_default_locale` is on -
   in which case they are redirected or stay on `/about`.
3. **Fallback**: when no signal matches, the configured default
   locale is used.

> **Note**: a switcher link to plain `/about` carries no locale
> signal - `RedirectLocale` would send the user back to their session
> locale instead of switching. See
> [Language Switcher](https://localizer.adam-nielsen.de/language-switcher) for more.

## Install

```bash
composer require niels-numbers/laravel-localizer
```

[Setup guide](https://localizer.adam-nielsen.de/installation) · [Migrating from `mcamara/laravel-localization`?](https://localizer.adam-nielsen.de/migrating-from-laravel-localization)

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