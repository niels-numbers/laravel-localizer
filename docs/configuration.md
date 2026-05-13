# Configuration

Publish the config file with:

```bash
php artisan vendor:publish --provider="NielsNumbers\\LaravelLocalizer\\ServiceProvider" --tag=config
```

This creates `config/localizer.php`.

| Key | Type | Default | Description |
|-----|------|----------|--------------|
| `supported_locales` | `array` | `[]` | List of all available locales. Example: `['en', 'de']`. |
| `hide_default_locale` | `bool` | `true` | If `true`, URLs using the **default (fallback)** locale will be redirected to the version **without** a locale prefix. Example: `/en/about` -> `/about`. |
| `persist_locale.session` | `bool` | `true` | If `true`, the detected locale is stored in the session. |
| `persist_locale.cookie` | `bool` | `true` | If `true`, the detected locale is stored in a browser cookie. |
| `detectors` | `array` | `[UserDetector::class, BrowserDetector::class]` | Ordered list of classes used to detect a user's locale when no locale is found in the URL, session, or cookie. See [Detectors](/detectors). |
| `redirect_enabled` | `bool` | `true` | Enables or disables automatic redirects between prefixed and unprefixed routes. See [Redirects](/redirects). |
| `locale_directions` | `array` | `[]` | Per-locale override for writing direction (`'rtl'` or `'ltr'`). Wins over auto-detection. See [Locale direction](/template-helpers#locale-direction). |

## Default locale

The package's reference for the **default locale** is
`config('app.fallback_locale')` (in `config/app.php`), not a localizer
config of its own. It's the base for `hide_default_locale` and the
fallback language for missing translations.

For multi-tenant apps where the default varies per request, override it
via `Localizer::setActiveDefaultLocale()` instead of mutating
`app.fallback_locale` directly. See [Multitenancy](/multitenancy) for
the rationale and the reset pattern.

## `app.locale` vs `app.fallback_locale`

- `config('app.fallback_locale')` is the package's reference for the
  default locale. Set it in `config/app.php`.
- `config('app.locale')` is updated at runtime by the `SetLocale`
  middleware via `App::setLocale()`. Its initial value in
  `config/app.php` has no lasting effect once the middleware runs.
