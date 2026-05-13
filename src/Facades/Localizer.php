<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array<int, string> supportedLocales()
 * @method static bool isSupported(?string $locale)
 * @method static ?string canonicalize(?string $locale)
 * @method static array<int, string> activeLocales()
 * @method static bool isActive(?string $locale)
 * @method static void setActiveLocales(?array<int, string> $locales)
 * @method static string defaultLocale()
 * @method static void setActiveDefaultLocale(?string $locale)
 * @method static bool hideDefaultLocale()
 * @method static bool storesInSession()
 * @method static bool storesInCookie()
 * @method static array<int, class-string> detectors()
 * @method static ?string detectLocale(\Illuminate\Http\Request $request)
 * @method static void setLocale(string $locale)
 * @method static string url(string $name, ?string $locale = null)
 * @method static ?string baseName(?string $name)
 * @method static string localeDirection(string $locale)
 * @method static string currentLocaleDirection()
 */
class Localizer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \NielsNumbers\LaravelLocalizer\Localizer::class;
    }
}
