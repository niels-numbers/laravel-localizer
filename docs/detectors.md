# Detectors

## Resolution order

`SetLocale` walks these sources, first match wins:

1. **URL** - `{locale}` segment of a `with_locale.*` route (`/de/about` -> `de`).
2. **Route action** - the `locale` action attribute set by `Route::translate()`.
3. **Session** - locale from a previous request.
4. **Cookie** - persisted client-side.
5. **Detectors** - see below.
6. **Default locale** - `localizer.default_locale`, or `app.fallback_locale` if not set.

Only **URL** and **route action** override an existing session/cookie.
Without a URL signal (request came in as `/about`, not `/de/about`),
`SetLocale` keeps the session/cookie value. That way a user who picked
German isn't reset to English on every unprefixed link, and
`RedirectLocale` can send them to the prefixed variant.

This is also why a switcher uses `Route::localizedSwitcherUrl()`
(always prefixed): the URL itself flips the active locale on click.
See [Language Switcher](/language-switcher).

## Built-in detectors

Detectors run only when steps 1-3 produce nothing (typically a first
visit). Two are registered by default:

- **`UserDetector`** - reads from the authenticated user model.
- **`BrowserDetector`** - reads `Accept-Language`.

## Custom detectors

Add to `localizer.detectors`:

```php
// config/localizer.php
'detectors' => [
    \App\Locale\CustomDetector::class,
    \NielsNumbers\LaravelLocalizer\Detectors\UserDetector::class,
    \NielsNumbers\LaravelLocalizer\Detectors\BrowserDetector::class,
],
```

Implement `DetectorInterface`:

```php
namespace App\Locale;

use Illuminate\Http\Request;
use NielsNumbers\LaravelLocalizer\Contracts\DetectorInterface;

class CustomDetector implements DetectorInterface
{
    public function detect(Request $request): ?string
    {
        return $request->header('X-App-Locale');
    }
}
```

Return a locale string, or `null` to defer to the next detector.
