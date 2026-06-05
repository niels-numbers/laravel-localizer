<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Macros;

use Closure;
use Illuminate\Support\Facades\Config;
use NielsNumbers\LaravelLocalizer\Routing\Concerns\RegistersLocaleRouteGroups;

class LocalizeMacro
{
    use RegistersLocaleRouteGroups;

    public function register(Closure $closure): void
    {
        $attributes = [
            'as' => 'with_locale.',
            'prefix' => '{locale}',
            'locale_type' => 'with_locale',
        ];

        // Constrain the {locale} placeholder to the configured supported
        // locales. Without this, the wildcard matches anything and a request
        // for /about against a Route::localize() that contains a `/` route
        // would match `with_locale.home` with locale='about' - wrong route,
        // wrong controller. Falls back to a never-matching pattern when
        // supported_locales is empty so the macro stays safe to call before
        // config is fully populated (e.g. early service-provider order).
        //
        // The pattern uses per-letter character classes ([Ee][Nn]) so the
        // match is case-insensitive in both PHP (PCRE) and JavaScript. A
        // wrong-case prefix like /EN/about (typical of stale email links)
        // still matches the route and reaches RedirectLocale, which then
        // redirects to the canonical lowercase form. PCRE's inline modifier
        // (?i) would be shorter but is invalid in JavaScript - Ziggy ships
        // the where constraint verbatim into a `new RegExp("(?<locale>...)")`
        // and Firefox rejects the inline flag as "invalid regexp group".
        $supported = Config::get('localizer.supported_locales', []);
        $attributes['where'] = ['locale' => $supported
            ? implode('|', array_map(fn ($l) => self::caseInsensitivePattern($l), $supported))
            : '(?!)'];

        $this->groupKeepingUnnamedRoutesUnnamed($attributes, $closure);

        $this->groupKeepingUnnamedRoutesUnnamed([
            'as' => 'without_locale.',
            'locale_type' => 'without_locale',
        ], $closure);
    }

    /**
     * Build a case-insensitive PCRE/JS-compatible alternative for one locale
     * by mapping each ASCII letter to a [Aa] character class. Non-letter
     * characters are preg_quoted so locales like "zh-Hans" stay safe.
     */
    private static function caseInsensitivePattern(string $locale): string
    {
        $pattern = '';
        foreach (str_split($locale) as $char) {
            $pattern .= ctype_alpha($char)
                ? '['.strtoupper($char).strtolower($char).']'
                : preg_quote($char, '/');
        }

        return $pattern;
    }
}
