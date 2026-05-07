<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Macros;

use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

final class LocalizeMacro
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
        $supported = Config::get('localizer.supported_locales', []);
        $attributes['where'] = ['locale' => $supported
            ? implode('|', array_map(fn ($l) => preg_quote($l, '/'), $supported))
            : '(?!)'];

        Route::group($attributes, $closure);

        Route::group([
            'as' => 'without_locale.',
            'locale_type' => 'without_locale',
        ], $closure);
    }
}
