# Changelog

All notable changes to `laravel-localizer` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.1] - 2026-05-08

### Fixed

- `RedirectLocale`: when `hide_default_locale` is `false`, requests to unprefixed URLs running on the default locale now redirect to the prefixed variant (`/about` -> `/en/about` when `en` is the default). Previously the middleware only added a prefix for non-default locales, implicitly assuming `hide_default_locale: true` - so apps that wanted the locale visible in every URL ended up with `/about` staying on `/about` while non-default locales did redirect, producing inconsistent URLs and no canonical form for the default locale.
- `LocalizeMacro`: the `{locale}` route constraint is now case-insensitive (`(?i)en|de|...`), so a wrong-case prefix like `/EN/about` (typical of stale email links) still matches the registered route and reaches `RedirectLocale`, which then redirects to the canonical lowercase form. Previously the constraint was case-sensitive, so `/EN/about` did not match the route at all and 404'd before the redirect logic in `RedirectLocale` (added in 1.1.1) ever got a chance to run - making the wrong-case redirect effectively dead code in real-world usage with `Route::localize()`. **Note:** this only fixes wrong-case prefixes for `Route::localize()` routes. `Route::translate()` uses literal locale prefixes (`/de`, `/fr`) which Laravel's router still matches case-sensitively; wrong-case URLs to translated routes continue to 404.

### Docs

- New "Middleware order with translated route bindings" section in `docs/caveats-and-recipes.md` explaining why `SetLocale` has to run after `StartSession` and before `SubstituteBindings`, with the `remove`/`append` snippet to land it in the correct slot. Triggered by an issue report (#6) where the documented `web(append: [SetLocale, RedirectLocale])` registration left `SetLocale` running after `SubstituteBindings`, breaking translated route bindings (`{post:slug}` resolved against the fallback locale and 404'd).
- Installation, multitenancy and migration-from-laravel-localization snippets all updated to the `remove`/`append` pattern so the package's recommended setup is correct out of the box.

## [1.2.0] - 2026-05-06

### Added

- `$route->baseName()` (macro on `Illuminate\Routing\Route`) and `Route::currentBaseName()` (facade shortcut for `Route::current()?->baseName()`): return a route's bare base name with the localizer prefix stripped (`with_locale.about` / `without_locale.about` / `translated_de.about` all collapse to `about`). Lets app code keep `$route->baseName() === 'about'` checks intact in middleware, gates, analytics and breadcrumb lookups even though the macros register the route under a prefixed name. Foreign-named routes (e.g. `admin.dashboard`) and unnamed routes pass through unchanged.
- `Localizer::baseName(?string)`: same logic exposed as a public method on the facade for stripping arbitrary route names (e.g. read from logs or queue payloads).

### Changed

- `CurrentRouteLocalizer` now delegates prefix-stripping to `Localizer::baseName()` instead of carrying its own private `stripLocalizerPrefix()` helper.

### Docs

- New `Route::baseName()` / `Route::currentBaseName()` section in `docs/template-helpers.md` with middleware and current-request examples.
- New caveat in `docs/caveats-and-recipes.md`: `$route->getName()` returns the prefixed variant — point readers at the new helpers.
- Expanded the existing `Route::hasLocalized()` section to spell out *why* `Route::has()` returns `false` for localizer-managed names, so readers running into the same surprise as a recent issue report find the answer directly. Mirrored as a separate caveat entry that links into the helpers page.

## [1.1.1] - 2026-05-05

### Fixed

- Locale matching is now case-insensitive across the whole package. App code passing uppercase locale codes (`App::setLocale('EN')`, `route('about', ['locale' => 'DE'])`, or detector return values from legacy DB columns like `idSprache = 'DE'`) previously produced URLs like `/EN/about` that didn't match the lowercase `LocalizeMacro` route constraint and 404'd, and made `__('...')` miss `lang/de/*` lookups. Every locale value now flows through `Localizer::canonicalize()` against `supported_locales` before it lands in a URL, the session, the cookie, or `App::setLocale()`. Mailables triggered after `App::setLocale($user->idSprache)` (e.g. `Notifiable::sendPasswordResetNotification()`) now generate canonical lowercase URLs and load the matching translation files without an app-side `strtolower()` workaround.

### Added

- `Localizer::canonicalize(?string)`: case-insensitive lookup against `supported_locales`, returning the canonical form. Pass-through for `null` and for values not in the config (e.g. `'klingon'`), so callers that intentionally use unsupported locales keep their existing behaviour.
- `RedirectLocale`: incoming URLs with a wrong-case prefix (e.g. `/EN/foo` from a stale email) now redirect to the canonical form (`/foo` when the default is hidden, `/en/foo` otherwise) instead of falling through to a 404.

### Changed

- `Localizer::isSupported()` and `Localizer::isActive()` are now case-insensitive (`strcasecmp` instead of `in_array(..., true)`). **Behaviour change**: callers who relied on these returning `false` for uppercase input now get `true`. In practice the convention is lowercase, so this matches what users meant; the change is safe for apps that already pass lowercase values.

## [1.1.0] - 2026-05-04

### Added

- `Localizer::setActiveDefaultLocale(?string)` and `Localizer::defaultLocale()`: per-request runtime override for the package's default locale (the one whose URLs are unprefixed when `hide_default_locale` is on). Mirrors the `setActiveLocales()` pattern - request-scoped property on the Localizer singleton, defaulting to `config('app.fallback_locale')`. Lets multi-tenant apps swap the default locale per request without mutating Laravel's translation fallback or leaking state across Octane workers.
- `NielsNumbers\LaravelLocalizer\Routing\LocalizerZiggyV2`: package-shipped Ziggy adapter for `tighten/ziggy` v2+. Bind `\Tighten\Ziggy\Ziggy::class` to it in `AppServiceProvider::register()` to make `route('about')` locale-aware in JS.
- `NielsNumbers\LaravelLocalizer\Routing\LocalizerZiggyV1` + `LocalizerBladeRouteGeneratorV1`: same for `tightenco/ziggy` v1, which instantiates Ziggy directly inside its `BladeRouteGenerator` and bypasses the container - so the generator itself has to be rebound.
- `NielsNumbers\LaravelLocalizer\Routing\Concerns\RewritesRoutesForLocale`: shared trait holding the locale-aware route-manifest rewrite logic used by both Ziggy adapters.
- Composer `suggest` entries for `tighten/ziggy` and `tightenco/ziggy` pointing at the matching adapter.

### Changed

- `UrlGenerator`, `RedirectLocale`, `SetLocale` and `CurrentRouteLocalizer` now read the default locale through `Localizer::defaultLocale()` instead of `config('app.fallback_locale')` directly. No behaviour change for non-tenant apps; tenant apps using `setActiveDefaultLocale()` see the override applied to URL generation, redirects, and the SetLocale fallback. `TranslateMacro` still reads `Config` directly because the `without_locale.*` variant is baked at boot.
- README: TOC restructured into six grouped sections (Getting Started, Defining Routes, Rendering URLs, Runtime Behavior, Advanced, About) with indented sub-bullets.

### Docs

- New `docs/multitenancy.md`: full multitenancy guide, including the new `setActiveDefaultLocale()` flow and the `Route::translate()` boot-vs-middleware caveat. Replaces the in-README section.
- New `docs/caveats-and-recipes.md`: edge cases (route name collisions, controller arguments, middleware order, route model binding with translated slugs) extracted from the README.
- `docs/javascript-route-helpers.md`: rewritten around the shipped adapters - users now only add a one-line container bind in `AppServiceProvider` instead of copying the adapter into `app/Routing/`.
- `docs/language-switcher.md`: cleanup - dropped the misleading "Inertia bridge" wording and the experimental SPA switcher info box. The `inertia-spa-language-switch.md` page is no longer linked from the sidebar.
- `docs/jobs-mailables-notifications.md`: removed internal-Laravel `withLocale()` mechanics and framework PR/issue links from the Mailables and Notifications sections; both are described as "automatic" since users only see the `->locale()` / `HasLocalePreference` API.
- `docs/caveats-and-recipes.md`: clarified that `SetLocale` only overwrites `app.locale` inside `Route::localize()` / `Route::translate()`. For plain unlocalized routes, console commands, and jobs, the initial `config/app.php` value stays in effect.
- `docs/comparison.md`: trimmed the `mcamara/laravel-localization` section to "long legacy with architectural limitations" and a link to the migration guide's "Why migrate" section, removing the duplicated bullet list.
- `docs/migrating-from-laravel-localization.md`: rewrote the "Why migrate" section. Verbose paragraphs and the deep-dive into `getRouteNameFromAPath` / `parse_url` internals are gone, replaced by punch-line bullets. New bullets for **Multi-tenant ready** (`setActiveLocales()` / `setActiveDefaultLocale()`) and **Modular architecture friendly** (per-module `Route::localize()` vs the original's per-module `LaravelLocalization::setLocale()` side effect that re-ran on every module's route group).

## [1.0.1] - 2026-05-03

### Added

- Laravel 13 support: CI matrix and `orchestra/testbench` require-dev extended to `^11.0` (Testbench 11 covers Laravel 13).

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

[Unreleased]: https://github.com/niels-numbers/laravel-localizer/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/niels-numbers/laravel-localizer/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/niels-numbers/laravel-localizer/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/niels-numbers/laravel-localizer/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/niels-numbers/laravel-localizer/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/niels-numbers/laravel-localizer/compare/v0.10.0...v1.0.0
[0.10.0]: https://github.com/niels-numbers/laravel-localizer/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/niels-numbers/laravel-localizer/releases/tag/v0.9.0
