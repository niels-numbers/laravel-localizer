# Changelog

All notable changes to `laravel-localizer` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-05-02

First stable release. No code changes since v0.10.0 — this release
marks the public API as stable and commits the project to semantic
versioning going forward. The API surface (`Route::localize()`,
`Route::translate()`, `SetLocale`, `RedirectLocale`, the `Localizer`
facade, `localizer.php` config keys) is now stable; breaking changes
will only happen in 2.x.

`mcamara/laravel-localization` is marked abandoned in Composer with
`niels-numbers/laravel-localizer` set as the replacement, courtesy of
[@mcamara](https://github.com/mcamara) merging
[mcamara/laravel-localization#955](https://github.com/mcamara/laravel-localization/pull/955).

## [0.10.0] - 2026-04-30

### Changed

- `SetLocale` now strips `{locale}` from the matched route's parameter bag after resolving the locale. Laravel passes bound route parameters to controller methods positionally; leaving `{locale}` in the bag meant a controller like `index($country = null)` would receive `'de'` (the locale) instead of `null` on `/de/users`. The locale is still available via `App::getLocale()` and `URL::defaults()`. **Behaviour change**: anyone who declared `$locale` as the first controller argument as a workaround will now receive the actual first URI parameter — drop the `$locale` argument.

## [0.9.0] - 2026-04-30

Initial public release. Architecture is stable; API may still see adjustments based on early-adopter feedback before 1.0.0.

### Added

- `Route::localize()` macro: registers each route twice (one with a `{locale}` prefix, one without). Fully compatible with `php artisan route:cache`.
- `Route::translate()` macro: per-locale URI paths via `lang/{locale}/routes.php` (e.g. `/about`, `/de/ueber`, `/fr/a-propos`), keyed on the full URI.
- `hide_default_locale` option: hides the prefix for the default locale (`/about` instead of `/en/about`).
- `SetLocale` middleware: resolves the active locale from URL → session → cookie → detector chain → `app.fallback_locale`.
- `RedirectLocale` middleware: redirects between prefixed and unprefixed variants to enforce the canonical form, preserving query strings. Skips non-safe methods (POST/PUT/PATCH/DELETE) to avoid losing the request body via the 302 → GET browser downgrade.
- Detector chain: `UserDetector` (reads from authenticated user) and `BrowserDetector` (`Accept-Language`); custom detectors via `localizer.detectors` config.
- `UrlGenerator` override: makes `route('about')` pick the locale-correct variant based on `App::getLocale()`, with explicit `['locale' => …]` override.
- Template helpers on the `Route` facade:
  - `Route::localizedUrl($locale)` — canonical URL of the current page in another locale, for `<link rel="alternate" hreflang="...">` and sitemaps.
  - `Route::localizedSwitcherUrl($locale)` — always-prefixed URL, for in-page language switchers.
  - `Route::hasLocalized($name)` / `Route::isLocalized()` — predicates for conditional rendering.
- `LocalizerZiggy` adapter pattern: container-bind `Tighten\Ziggy\Ziggy` to a subclass that rewrites the route manifest based on the current locale.
- Wayfinder helper pattern: `localizedRoute()` lookup wrapping the generated route modules.
- Locale propagation guidance for non-HTTP contexts (mailables, notifications, queued jobs) via `Mail::to()->locale()`, `HasLocalePreference`, and `Localizable::withLocale()`.
- Runtime active-locales subset for multitenancy: `Localizer::activeLocales()`, `isActive()`, `setActiveLocales(?array)`. Narrow the user-reachable locales per request without breaking `route:cache` (supported = static union; active = runtime subset).
- Mixed routes support: `SetLocale` and `RedirectLocale` skip routes not registered through `Route::localize()` / `Route::translate()`, detected via a `locale_type` action attribute the macros set. Plain routes (e.g. `/admin`, `/api/health`) coexist safely in the same `web` middleware group — no spurious redirects to non-existent locale-prefixed paths.
- Inertia + SPA language switcher guide (experimental) at `docs/inertia-spa-language-switch.md`.
- CI matrix: PHP 8.2–8.4 × Laravel 9–12 (Testbench 7–10).

[Unreleased]: https://github.com/niels-numbers/laravel-localizer/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/niels-numbers/laravel-localizer/compare/v0.10.0...v1.0.0
[0.10.0]: https://github.com/niels-numbers/laravel-localizer/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/niels-numbers/laravel-localizer/releases/tag/v0.9.0
