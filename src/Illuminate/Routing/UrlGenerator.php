<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Illuminate\Routing;

use Illuminate\Routing\UrlGenerator as BaseUrlGenerator;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use NielsNumbers\LaravelLocalizer\Facades\Localizer;

class UrlGenerator extends BaseUrlGenerator
{
    public function route($name, $parameters = [], $absolute = true)
    {
        // Laravel accepts scalar shortcuts: route('show', 1) is equivalent
        // to route('show', [1]). Reading $parameters['locale'] on a scalar
        // would emit a warning and (under strict error handlers) crash.
        if (! is_array($parameters)) {
            $parameters = [$parameters];
        }

        [$resolvedName, $parameters] = $this->resolveRouteName($name, $parameters);

        return parent::route($resolvedName, $parameters, $absolute);
    }

    /**
     * Pick the actual route name to hand to parent::route() and prepare its
     * parameters. The candidates are tried in priority order:
     *
     *   1. Exact match — caller already knows the route name.
     *   2. Hidden default locale — drop the prefix when both target and
     *      current app are in the default locale (see comment below).
     *   3. LocalizeMacro variant (`with_locale.{name}`, /{locale}/about).
     *   4. TranslateMacro variant (`translated_{locale}.{name}`, /de/ueber).
     *   5. Fallback — degrade to the default-locale variant and let
     *      parent::route() raise RouteNotFoundException if it doesn't exist.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function resolveRouteName(string $name, array $parameters): array
    {
        $urlLocale = $parameters['locale'] ?? null;
        $appLocale = App::getLocale();

        // Normalize casing so App::setLocale('EN') and route('about',
        // ['locale' => 'EN']) produce the same canonical URLs as their
        // lowercase counterparts. Falls back to the input unchanged when
        // not in supported_locales, preserving existing behavior for
        // callers passing unsupported values (e.g. 'klingon').
        $appLocale = Localizer::canonicalize($appLocale);
        if ($urlLocale !== null) {
            $urlLocale = Localizer::canonicalize($urlLocale);
            $parameters['locale'] = $urlLocale;
        }

        $defaultLocale = Localizer::defaultLocale();
        $hideDefault = Localizer::hideDefaultLocale();
        $locale = $urlLocale ?? $appLocale;

        if (Route::has($name)) {
            return [$name, $parameters];
        }

        // Hide the default-locale prefix only when both the requested target
        // AND the current app are in the default locale. If the app is in
        // another language and we're building a switch-link to the default,
        // we MUST keep the /en/about prefix so SetLocale detects the switch
        // from the URL — RedirectLocale strips it on the follow-up request.
        $withoutLocaleName = 'without_locale.'.$name;
        if ($hideDefault
            && $appLocale === $defaultLocale
            && ($urlLocale === null || $urlLocale === $defaultLocale)
            && Route::has($withoutLocaleName)) {
            // Drop the locale parameter — the target route doesn't declare
            // a {locale} placeholder, so Laravel would otherwise append
            // ?locale=xx as a query string.
            unset($parameters['locale']);

            return [$withoutLocaleName, $parameters];
        }

        $withLocaleName = 'with_locale.'.$name;
        if (Route::has($withLocaleName)) {
            $parameters['locale'] = $locale;

            return [$withLocaleName, $parameters];
        }

        $translatedName = "translated_{$locale}.".$name;
        if (Route::has($translatedName)) {
            // Locale is part of the route URI, not a parameter — same
            // query-string reason as the hide_default branch above.
            unset($parameters['locale']);

            return [$translatedName, $parameters];
        }

        // Fallback: caller passed an unsupported locale (e.g.
        // route('about', ['locale' => 'klingon'])) or none of the variants
        // exist. Degrade to the default-locale variant; the hideDefault
        // branch keeps the output consistent with the convention and
        // avoids an unnecessary RedirectLocale round-trip.
        return [
            $hideDefault ? $withoutLocaleName : "translated_{$defaultLocale}.".$name,
            $parameters,
        ];
    }
}
