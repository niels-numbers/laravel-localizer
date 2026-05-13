<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Services;

use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Traits\Localizable;
use LogicException;
use NielsNumbers\LaravelLocalizer\Localizer;

/**
 * Backs `Route::localizedUrl($locale)` — returns the current request's URL
 * in another locale, suitable for language switchers and `<link rel="alternate">`
 * tags.
 *
 * Resolution strategy:
 *   1. The current route is named (recommended): use the base name and let the
 *      package's UrlGenerator override pick the right variant for the new
 *      locale. The call is wrapped in withLocale() so the override sees the
 *      *target* locale and produces the canonical URL (no /en prefix when
 *      English is the hidden default).
 *   2. The current route was registered through Route::localize() but isn't
 *      named: fall back to a URI prefix swap on the request path.
 *   3. The current route was registered through Route::translate() but isn't
 *      named: throw — the original lang-key is unrecoverable from the URI.
 *   4. There's no current route (called outside an HTTP request): throw.
 */
class CurrentRouteLocalizer
{
    use Localizable;

    public function __construct(
        protected Localizer $localizer
    ) {}

    public function localize(string $locale, bool $absolute = true, bool $forcePrefix = false): string
    {
        $current = Route::current();

        if ($current === null) {
            throw new LogicException(
                'Route::localizedUrl() requires an active matched route. '
                .'Are you calling it outside an HTTP request?'
            );
        }

        $name = $current->getName();
        $baseName = $this->localizer->baseName($name);

        return $this->withLocale($locale, function () use ($current, $name, $baseName, $locale, $absolute, $forcePrefix) {
            if ($baseName !== '' && $baseName !== null) {
                return $this->localizeNamedRoute($current, $name, $baseName, $locale, $absolute, $forcePrefix);
            }

            // Unnamed route. URI prefix swap is only safe for LocalizeMacro
            // routes (every variant shares the same path, only the prefix
            // differs). TranslateMacro routes carry `locale_type = translated`
            // and have locale-specific URIs (/about vs. /de/ueber) — no
            // prefix swap can recover the original lang-key, so they throw.
            if (in_array($current->getAction('locale_type'), ['with_locale', 'without_locale'], true)) {
                return $this->swapUriPrefix(request(), $locale, $absolute, $forcePrefix);
            }

            throw new LogicException(
                'Route::localizedUrl() cannot switch the locale of an unnamed translated route. '
                .'Add ->name() inside Route::translate() so the helper can resolve the URL.'
            );
        });
    }

    protected function localizeNamedRoute(RouteInstance $current, ?string $name, string $baseName, string $locale, bool $absolute, bool $forcePrefix = false): string
    {
        // Restrict to actual URI placeholders. $current->parameters() also
        // contains route defaults (Route::view sets view/data/status/headers,
        // Route::redirect sets destination/status, ->defaults() is user-set),
        // and route() would append any non-placeholder key as a query string.
        $parameters = array_intersect_key(
            $current->parameters(),
            array_flip($current->parameterNames())
        );

        // Force-prefix mode (language switcher): bypass the UrlGenerator's
        // canonical-hiding branch by addressing the prefixed variant by name.
        // Without this, route($baseName) would resolve to without_locale.* for
        // target = default + hide_default, and the resulting unprefixed URL
        // would carry no locale signal - SetLocale would fall back to session.
        if ($forcePrefix) {
            $withLocaleName = 'with_locale.'.$baseName;
            if (Route::has($withLocaleName)) {
                $parameters['locale'] = $locale;

                return $this->withQueryString(route($withLocaleName, $parameters, $absolute));
            }

            $translatedName = "translated_{$locale}.".$baseName;
            if (Route::has($translatedName)) {
                unset($parameters['locale']);

                return $this->withQueryString(route($translatedName, $parameters, $absolute));
            }

            // Foreign-named route - no localized variant exists, nothing to
            // force. Fall through to the unforced behavior below.
        }

        // If the route is registered through one of our macros, override the
        // {locale} parameter; for foreign-named routes (admin.dashboard etc.)
        // we leave parameters alone so we don't append ?locale=xx as a stray
        // query string.
        if ($name !== null && $name !== $baseName) {
            $parameters['locale'] = $locale;
        }

        return $this->withQueryString(route($baseName, $parameters, $absolute));
    }

    protected function withQueryString(string $url): string
    {
        // request() is guaranteed non-null here: this method is only
        // reachable through localize(), which throws when no current
        // route exists - and there is no current route without a
        // request bound in the container.
        $query = request()->getQueryString();

        if (! $query) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').$query;
    }

    protected function swapUriPrefix(Request $request, string $newLocale, bool $absolute, bool $forcePrefix = false): string
    {
        $path = ltrim($request->path(), '/');
        [$prefix, $rest] = array_pad(explode('/', $path, 2), 2, '');

        $bare = $this->localizer->isSupported($prefix) ? $rest : $path;
        $hide = $this->localizer->hideDefaultLocale();
        $default = $this->localizer->defaultLocale();

        $newPath = (! $forcePrefix && $hide && $newLocale === $default)
            ? $bare
            : $newLocale.'/'.$bare;

        $url = '/'.ltrim($newPath, '/');
        $query = $request->getQueryString();

        if ($query) {
            $url .= '?'.$query;
        }

        return $absolute ? url($url) : $url;
    }
}
