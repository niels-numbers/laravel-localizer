---
layout: home

hero:
  name: Laravel Localizer
  text: Locale detection and redirects for Laravel routes
  tagline: Laravel can prefix routes with /{locale}. This package adds what is missing - detect the visitor's language, redirect them there, remember the choice, and keep route('about') returning the right URL.
  actions:
    - theme: brand
      text: Get Started
      link: /installation
    - theme: alt
      text: Migrating from mcamara?
      link: /migrating-from-laravel-localization
    - theme: alt
      text: View on GitHub
      link: https://github.com/niels-numbers/laravel-localizer

features:
  - title: One definition, every language
    details: Wrap your routes once and get /about, /de/about, /fr/about. route('about') returns the right one for the current language.
  - title: Automatic detection and redirect
    details: The first visit goes by browser language, user profile, or your own logic. After that the URL decides, and the choice is remembered in session and cookie.
  - title: Translated paths
    details: /about, /de/ueber, /fr/a-propos. One route name, one path per language in a lang file.
  - title: Plain Laravel routes
    details: Every variant is a static, named route. route:cache, Livewire, Inertia with Ziggy or Wayfinder, and multi-tenant setups all work.
---

## Example

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

The default language has no prefix because
[`hide_default_locale`](/configuration) is on by default. Turn it off
and every URL carries a prefix: `/about` then redirects to `/en/about`.

`route('about')` keeps working as before and returns the URL for the
current language: in Blade, controllers, jobs, mails and, with the
[JavaScript route helpers](/javascript-route-helpers), in your Vue or
React frontend.

Want `/de/ueber` and `/fr/a-propos` instead of `/de/about`? See
[Translated URL Paths](/translated-url-paths).

## What happens on a visit

1. **First visit**: someone opens `/about`. The package reads their
   browser language (or your own [detectors](/detectors)) and redirects
   them, for example to `/de/about`. English visitors stay on `/about`.
2. **From then on the URL decides**: `/de/about` shows German and
   remembers it in session and cookie. A link to plain `/about` sends
   that visitor back to `/de/about`.
3. **One canonical URL per page**: the default language has no prefix,
   so `/en/about` [redirects](/redirects) to `/about`.

A language switcher is one helper call per language:
`Route::localizedSwitcherUrl('fr')`. See
[Language Switcher](/language-switcher).

## Coming from `mcamara/laravel-localization`?

This package is its successor. Same job, different wiring: every
localized route is a plain, static Laravel route, so `route:cache`
works, translated routes switch reliably and Livewire works out of the
box. The [migration guide](/migrating-from-laravel-localization) covers
the differences step by step.

Not sure you need this package at all? See
[when to use it and how it compares](/comparison) to
`mcamara/laravel-localization`, `codezero-be/laravel-localized-routes`
and `spatie/laravel-translatable`.
