<?php

namespace NielsNumbers\LaravelLocalizer\Macros;

use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

class LocalizeMacro
{
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
        // would match `with_locale.home` with locale='about' — wrong route,
        // wrong controller. Falls back to a never-matching pattern when
        // supported_locales is empty so the macro stays safe to call before
        // config is fully populated (e.g. early service-provider order).
        //
        // The (?i) inline modifier makes the match case-insensitive, so a
        // wrong-case prefix like /EN/about (typical of stale email links)
        // still matches the route and reaches RedirectLocale, which then
        // redirects to the canonical lowercase form. Without (?i), the
        // route doesn't match, the request 404s, and RedirectLocale never
        // gets the chance to fix the URL.
        $supported = Config::get('localizer.supported_locales', []);
        $attributes['where'] = ['locale' => $supported
            ? '(?i)' . implode('|', array_map(fn ($l) => preg_quote($l, '/'), $supported))
            : '(?!)'];

        Route::group($attributes, $closure);

        Route::group([
            'as' => 'without_locale.',
            'locale_type' => 'without_locale',
        ], $closure);
    }
}
