<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use NielsNumbers\LaravelLocalizer\Contracts\DetectorInterface;
use NielsNumbers\LaravelLocalizer\Localizer;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function __construct(protected Localizer $localizer) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Skip routes not registered through Route::localize() / Route::translate().
        // The macros tag their groups with a `locale_type` action attribute;
        // routes outside them have none, so we leave them untouched. Lets
        // unlocalized routes (e.g. /admin, /api/health) coexist in the same
        // middleware group without being forced into locale logic.
        if ($request->route()?->getAction('locale_type') === null) {
            return $next($request);
        }

        $locale = $this->detectLocale($request) ?? $this->localizer->defaultLocale();

        // Always store the canonical form so downstream consumers see one
        // representation regardless of source (URL segment, session, cookie,
        // or detector). Keeps App::getLocale() consistent with config keys
        // (`lang/de/...`, not `lang/DE/...`).
        $locale = $this->localizer->canonicalize($locale);

        if ($this->localizer->storesInSession()) {
            Session::put('locale', $locale);
        }

        if ($this->localizer->storesInCookie()) {
            Cookie::queue('locale', $locale, 60 * 24 * 30);
        }

        App::setLocale($locale);
        URL::defaults(['locale' => $locale]);

        // Drop {locale} from the matched route's parameter bag. Laravel passes
        // bound route parameters to the controller method positionally (in URI
        // order), so leaving it in surprises every controller with an optional
        // first argument — `index($country = null)` on /de/users would receive
        // 'de' instead of null. App::getLocale() and URL::defaults() already
        // carry the locale; the controller has no business consuming it again.
        // Route is guaranteed non-null here: the early return at the top
        // of the method bails out when no route matched.
        $request->route()->forgetParameter('locale');

        return $next($request);
    }

    protected function detectLocale(Request $request): ?string
    {
        $routeLocale = $request->route('locale');
        if ($this->localizer->isActive($routeLocale)) {
            return $routeLocale;
        }

        // Translated routes carry a literal locale prefix (/de, /fr) and no
        // {locale} URL parameter — TranslateMacro stores the locale in the
        // route action so we can recover it here.
        $actionLocale = $request->route()?->getAction('locale');
        if ($this->localizer->isActive($actionLocale)) {
            return $actionLocale;
        }

        if ($this->localizer->storesInSession()) {
            $sessionLocale = Session::get('locale');
            if ($this->localizer->isActive($sessionLocale)) {
                return $sessionLocale;
            }
        }

        if ($this->localizer->storesInCookie()) {
            $cookieLocale = $request->cookie('locale');
            if ($this->localizer->isActive($cookieLocale)) {
                return $cookieLocale;
            }
        }

        return $this->detectLocaleFromDetectors($request);
    }

    protected function detectLocaleFromDetectors(Request $request): ?string
    {
        foreach ($this->localizer->detectors() as $detectorClass) {
            $detector = app($detectorClass);
            if (! $detector instanceof DetectorInterface) {
                continue;
            }

            $result = $detector->detect($request);

            foreach ((array) $result as $candidate) {
                if ($this->localizer->isActive($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
