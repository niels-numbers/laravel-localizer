# Configuration

Publish the config file with:

```bash
php artisan vendor:publish --provider="NielsNumbers\\LaravelLocalizer\\ServiceProvider" --tag=config
```

This creates `config/localizer.php`.

| Key | Type | Default | Description |
|-----|------|----------|--------------|
| `supported_locales` | `array` | `[]` | List of all available locales. Example: `['en', 'de']`. |
| `hide_default_locale` | `bool` | `true` | If `true`, URLs using the **default** locale will be redirected to the version **without** a locale prefix. Example: `/en/about` -> `/about`. |
| `persist_locale.session` | `bool` | `true` | If `true`, the detected locale is stored in the session. |
| `persist_locale.cookie` | `bool` | `true` | If `true`, the detected locale is stored in a browser cookie. |
| `detectors` | `array` | `[UserDetector::class, BrowserDetector::class]` | Ordered list of classes used to detect a user's locale when no locale is found in the URL, session, or cookie. See [Detectors](/detectors). |
| `redirect_enabled` | `bool` | `true` | Enables or disables automatic redirects between prefixed and unprefixed routes. See [Redirects](/redirects). |
| `locale_directions` | `array` | `[]` | Per-locale override for writing direction (`'rtl'` or `'ltr'`). Wins over auto-detection. See [Locale direction](/template-helpers#locale-direction). |

## Default locale

Three locale settings play a role, and they mean different things:

- **Default locale** (`app.locale` in `config/app.php`): the site's main
  language. URLs in this locale have no prefix (when
  `hide_default_locale` is on), and visitors get it when nothing else
  (URL, session, cookie, detectors) determines a locale.
- **Fallback locale** (`app.fallback_locale`): Laravel's translation
  fallback. If a string is missing in the current locale, Laravel uses
  the fallback locale's string instead. It has no effect on URLs.
- **Current locale** (`App::getLocale()`): the locale of the current
  request. `SetLocale` sets it on every request via `App::setLocale()`,
  which also overwrites `config('app.locale')`. The package keeps the
  default it read at boot, so this doesn't affect the default locale.

Example: the site's main language is `de`, but missing translations
should fall back to `en`:

```php
// config/app.php
'locale' => 'de',
'fallback_locale' => 'en',
```

For multi-tenant apps where the default varies per request, use
`Localizer::setActiveDefaultLocale()`. See [Multitenancy](/multitenancy).

