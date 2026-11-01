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

### [`codezero-be/laravel-localized-routes`](https://github.com/codezero-be/laravel-localized-routes) (deprecated)

Route-per-locale (Nx routes per definition). No longer maintained, but
its design ideas influenced this one - here only two routes per
definition.

### [`spatie/laravel-translatable`](https://github.com/spatie/laravel-translatable)

Translates **Eloquent model fields**, not routes. Works alongside this
package for translatable slugs.

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
solved this with **static per-locale routes** (Nx per definition).
This package takes the **middle path**: each route registered
**twice** (one with `{locale}`, one without).

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

Since [@codezero-be](https://github.com/codezero-be) is no longer with
us, I want to acknowledge his great work and influence on this package.
Many of his ideas live on here, and I hope this helps to keep his
contributions useful to the Laravel community for years to come.
