<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Macros;

use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Traits\Localizable;
use NielsNumbers\LaravelLocalizer\Facades\Localizer;
use NielsNumbers\LaravelLocalizer\Routing\Concerns\RegistersLocaleRouteGroups;
use NielsNumbers\LaravelLocalizer\Services\UriTranslator;

class TranslateMacro
{
    use Localizable;
    use RegistersLocaleRouteGroups;

    public function __construct(
        protected UriTranslator $translator
    ) {}

    /**
     * Register translated routes for all supported locales.
     *
     * For each supported locale:
     * - prefixes the route with the locale (e.g. /de/about)
     * - uses translated URIs (the user's closure calls Localizer::uri('...'),
     *   which reads App::getLocale() — hence the per-iteration withLocale)
     * - creates a "without locale" route for the fallback locale when
     *   hide_default_locale is on
     *
     * Each group sets a `locale` action attribute carrying the registered
     * locale. Translated routes use literal prefixes (/de, /fr), so they
     * carry no {locale} URL parameter; SetLocale reads getAction('locale')
     * to recover the locale from the route — same pattern as locale_type
     * in LocalizeMacro.
     */
    public function register(Closure $routes): void
    {
        $supported = Localizer::supportedLocales();
        // Boot-time decision: which locale gets the without_locale.* variant
        // is baked into the route registration and can't change per request.
        // Read Config directly (not Localizer::defaultLocale()) to make it
        // explicit that any per-request setActiveDefaultLocale() override is
        // intentionally ignored here — see docs/multitenancy.md.
        $default = Config::get('app.fallback_locale');
        $hide = Localizer::hideDefaultLocale();

        foreach ($supported as $locale) {
            $this->withLocale($locale, function () use ($routes, $locale, $default, $hide) {
                $this->groupRequiringNamedRoutes([
                    'prefix' => $locale,
                    'as' => "translated_$locale.",
                    'locale' => $locale,
                    'locale_type' => 'translated',
                ], $routes);

                // For the default locale: register a no-prefix variant.
                // The route name namespace ('without_locale.') prevents a
                // collision with the LocalizeMacro's own without-locale
                // routes.
                if ($locale === $default && $hide) {
                    $this->groupRequiringNamedRoutes([
                        'as' => 'without_locale.',
                        'locale' => $locale,
                        // 'translated' (not 'without_locale'): the URI here
                        // is locale-specific (e.g. /about for en, but the
                        // route's German equivalent lives at /de/ueber, not
                        // /de/about). CurrentRouteLocalizer must NOT do a
                        // URI prefix swap for these — distinct from the
                        // LocalizeMacro's without_locale routes where every
                        // variant shares the same URI.
                        'locale_type' => 'translated',
                    ], $routes);
                }
            });
        }
    }
}
