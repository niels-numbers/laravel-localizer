# Comparison & Background

## When to use this package

Use it for:

- automatic locale detection (browser, session, cookie, custom)
- automatic redirects to localized routes
- hidden default locale in URLs (`/about` instead of `/en/about`)
- fully translatable routes (`/en/humans` vs `/de/menschen`)

You don't need it if you're fine with `example.com/de/blog` /
`example.com/en/blog` only and don't need detection or unprefixed
defaults.

## Comparison to other packages

### [`mcamara/laravel-localization`](https://github.com/mcamara/laravel-localization)

The original package this one succeeds. Still maintained by
[@jordyvanderhaegen](https://github.com/jordyvanderhaegen) for
Laravel/PHP compatibility, security, and small bug fixes. Long legacy
codebase whose original architecture comes with limitations that
motivated this rewrite - see
[Why migrate](/migrating-from-laravel-localization#why-migrate).

### [`codezero-be/laravel-localized-routes`](https://github.com/codezero-be/laravel-localized-routes) (unmaintained)

Static route-per-locale approach. No longer maintained and doesn't
support Laravel 12+, but its design ideas influenced this package.

### [`chinleung/laravel-multilingual-routes`](https://github.com/chinleung/laravel-multilingual-routes)

Static route-per-locale approach via `Route::multilingual()`, with
translated paths from `lang/{locale}/routes.php`. The middleware only
reads the locale from the first URL segment - no browser/session
detection or redirects to the visitor's language. A good fit if the URL
alone should decide the language.

**`route()` doesn't work for localized routes.** Route names carry the
locale (`en.about`, `de.about`), so `route('about')` throws a
`RouteNotFoundException`. Every URL needs `localized_route('about')` or
an explicit `route('de.about')` - including `redirect()->route()`,
`to_route()`, signed URLs and route names used by third-party packages.
The same applies in JS with Ziggy or Wayfinder, which only see the
locale-prefixed names.

### [`spatie/laravel-translatable`](https://github.com/spatie/laravel-translatable)

Translates **Eloquent model fields**, not routes. Works alongside this
package for translatable slugs.

### How the routes are registered

The examples use two routes (`about` and `contact`) and four locales
(`en`, `de`, `fr`, `es`), as shown by `php artisan route:list`.

#### mcamara/laravel-localization

```
GET|HEAD  about ................................................ about
GET|HEAD  contact ............................................ contact
```

The locale prefix is read from the current request while routes
register. On the console there is no request, so `route:list` only
shows the unprefixed routes - `/de/about`, `/fr/contact` etc. exist at
runtime but don't appear in the list.

#### codezero-be/laravel-localized-routes

```
GET|HEAD  en/about ........................................... en.about
GET|HEAD  de/about ........................................... de.about
GET|HEAD  fr/about ........................................... fr.about
GET|HEAD  es/about ........................................... es.about
GET|HEAD  en/contact ....................................... en.contact
GET|HEAD  de/contact ....................................... de.contact
GET|HEAD  fr/contact ....................................... fr.contact
GET|HEAD  es/contact ....................................... es.contact
```

Every locale gets its own copy of every route: 2 routes x 4 locales =
8 routes. Each new locale adds another full set.

#### chinleung/laravel-multilingual-routes

```
GET|HEAD  about .............................................. en.about
GET|HEAD  de/about ........................................... de.about
GET|HEAD  fr/about ........................................... fr.about
GET|HEAD  es/about ........................................... es.about
GET|HEAD  contact .......................................... en.contact
GET|HEAD  de/contact ....................................... de.contact
GET|HEAD  fr/contact ....................................... fr.contact
GET|HEAD  es/contact ....................................... es.contact
```

Also one copy per locale, but the default locale has no prefix by
default. With translations in `lang/{locale}/routes.php` the URIs
differ per locale (`de/ueber`).

#### laravel-localizer (this package)

```
GET|HEAD  about ...................................... without_locale.about
GET|HEAD  contact .................................. without_locale.contact
GET|HEAD  {locale}/about ................................ with_locale.about
GET|HEAD  {locale}/contact ............................ with_locale.contact
```

Each route is registered **twice**, regardless of how many locales you
support: 2 routes x 2 = 4 routes, also with 10 locales. The locale is a regular `{locale}` placeholder. The second,
unprefixed route is needed because a route placeholder can't be empty,
so `/about` can't be matched by `{locale}/about`.

You never pass `{locale}` yourself: the middleware sets it via
[`URL::defaults()`](https://laravel.com/docs/urls#default-values), as
recommended by Laravel, so `route('about')` just works. It is also
removed from the route parameters, so controllers don't receive it as an
argument.

### What `/about` does

Default locale `en`. Every package has a global switch for whether the
default locale gets a URL prefix:

| Package | Setting |
|---|---|
| mcamara/laravel-localization | `hideDefaultLocaleInURL` |
| codezero-be/laravel-localized-routes | `omitted_locale` |
| chinleung/laravel-multilingual-routes | `prefix_default` (inverted) |
| laravel-localizer (this package) | `hide_default_locale` |

**Mode A: default locale without prefix** (`/about` is the English page)

| Package | English visitor opens `/about` | German visitor opens `/about` |
|---|---|---|
| mcamara | English page | Redirect to `/de/about`¹ |
| codezero | English page | English page |
| chinleung | English page | English page |
| laravel-localizer | English page | Redirect to `/de/about` |

**Mode B: every locale prefixed** (`/en/about`, `/de/about`)

| Package | English visitor opens `/about` | German visitor opens `/about` |
|---|---|---|
| mcamara | Redirect to `/en/about`¹ | Redirect to `/de/about`¹ |
| codezero | Redirect to `/en/about`² | Redirect to `/de/about`² |
| chinleung | 404, or redirect to `/en/about`³ | 404, or redirect to `/en/about`³ |
| laravel-localizer | Redirect to `/en/about` | Redirect to `/de/about` |

¹ Requires `LocaleSessionRedirect` (or `LocaleCookieRedirect`) and
`useAcceptLanguageHeader`.<br>
² Requires `redirect_to_localized_urls` and the fallback route.<br>
³ With `redirect_unprefixed`. There is no locale detection, so every
visitor goes to the default locale.

In mode A, codezero and chinleung can't send a German visitor from
`/about` to `/de/about`: `/about` is always the English page.

## Background

I (Adam Nielsen) was a collaborator on the original
[mcamara/laravel-localization](https://github.com/mcamara/laravel-localization).
Since @mcamara has moved on from Laravel,
[@jordyvanderhaegen](https://github.com/jordyvanderhaegen) continues
to maintain it for compatibility and small fixes. This rewrite
addresses long-standing limitations that follow from the original's
runtime route generation.

The original generated **dynamic routes** (cache + compatibility
issues).
[laravel-localized-routes](https://github.com/codezero-be/laravel-localized-routes)
solved this with **static per-locale routes** (one copy per locale).
This package takes the **middle path** - see
[How the routes are registered](#how-the-routes-are-registered).

## Why not a v3 of mcamara/laravel-localization?

The first attempt was exactly that: refactoring the original package in place ([#934](https://github.com/mcamara/laravel-localization/pull/934)). It turned out the core problems - building URLs by parsing and reverse-mapping the request URI, and calling `setLocale()` while routes register - weren't bugs in isolated code paths but consequences of the architecture itself. Fixing them meant removing nearly all of the existing code. Starting fresh also meant leaving behind code that had accumulated changes since 2013 and had become hard to follow. Testing got simpler too: with static routes, the tests don't need to reboot the app per locale or switch locales through an environment variable, and they can compile routes the same way Laravel's own `route:cache` does.

Releasing that as a new major version would have forced every existing user into a full migration, including the many apps where the current package works fine. A separate package keeps both paths open: `mcamara/laravel-localization` stays stable and maintained by [@jordyvanderhaegen](https://github.com/jordyvanderhaegen), and projects that need `route:cache`, reliable translated routes or Livewire support can migrate when it suits them.

## Credits

- [@mcamara](https://github.com/mcamara): original creator of
  [laravel-localization](https://github.com/mcamara/laravel-localization).
- [@codezero-be](https://github.com/codezero-be): developed a static
  route-per-locale approach (e.g. `en.index`, `de.index`, `es.index`).
  While this package follows a different routing strategy (two routes
  per definition: one with `{locale}` and one without), many classes
  and much of the implementation style are adapted from
  [laravel-localized-routes](https://github.com/codezero-be/laravel-localized-routes).
- [@jordyvanderhaegen](https://github.com/jordyvanderhaegen):
  co-maintainer of
  [laravel-localization](https://github.com/mcamara/laravel-localization);
  his issue
  [mcamara/laravel-localization#921](https://github.com/mcamara/laravel-localization/issues/921)
  was the motivation for writing this package.

Since Ivan Vermeyen ([@codezero-be](https://github.com/codezero-be)) is
no longer with us, I want to acknowledge his great work and influence
on this package.
Many of his ideas live on here, and I hope this helps to keep his
contributions useful to the Laravel community for years to come.
