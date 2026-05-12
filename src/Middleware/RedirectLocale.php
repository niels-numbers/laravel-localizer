<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use NielsNumbers\LaravelLocalizer\Localizer;
use Symfony\Component\HttpFoundation\Response;

class RedirectLocale
{
    public function __construct(protected Localizer $localizer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Config::get('localizer.redirect_enabled', true)) {
            return $next($request);
        }

        // Skip routes not registered through Route::localize() / Route::translate().
        // The macros tag their groups with a `locale_type` action attribute;
        // routes outside them have none, so we don't touch them. This lets
        // unlocalized routes (e.g. /admin, /api/health) coexist in the same
        // middleware group without being redirected into a locale prefix.
        if ($request->route()?->getAction('locale_type') === null) {
            return $next($request);
        }

        // Don't redirect non-safe methods. Browsers downgrade 302 from
        // POST/PUT/PATCH/DELETE to GET, dropping the request body — a
        // submitted form would silently lose its payload. The matched
        // route handler runs on whatever URL the client actually hit
        // (with_locale or without_locale variant); both register the
        // same controller, so behavior is identical bar the URL itself.
        if (! $request->isMethodSafe()) {
            return $next($request);
        }

        $locale = App::getLocale();
        $default = $this->localizer->defaultLocale();
        $hideDefault = Config::get('localizer.hide_default_locale', true);

        $path = ltrim($request->path(), '/');
        // We can't use $request->route('locale'): only LocalizeMacro routes
        // (/{locale}/about) declare it as a parameter. TranslateMacro routes
        // (/de/ueber), directly registered routes (Route::get('/de/foo')) and
        // without_locale.* routes don't — yet they all need this middleware.
        // So we look at the raw path and split off the first segment ourselves.
        [$rawPrefix, $rest] = array_pad(explode('/', $path, 2), 2, '');

        // The first path segment counts as a locale only if it's whitelisted —
        // matching by regex would either miss multi-character tags (zh-CN, pt-BR)
        // or treat any two-letter prefix as a locale, redirecting nonsense paths.
        $hasLocalePrefix = $this->localizer->isActive($rawPrefix);
        $prefix = $this->localizer->canonicalize($rawPrefix);

        // Wrong-case prefix matched a supported locale (e.g. /EN/foo from a
        // legacy email link) — redirect to the canonical case so the request
        // hits a registered route and the URL stays consistent with everything
        // else generated.
        if ($hasLocalePrefix && $prefix !== $rawPrefix) {
            $newPath = ($hideDefault && $prefix === $default) ? $rest : $prefix.'/'.$rest;

            return $this->redirectTo($request, $newPath);
        }

        // Locale prefix matches default + default should be hidden → strip it
        if ($hasLocalePrefix && $prefix === $default && $hideDefault) {
            return $this->redirectTo($request, $rest);
        }

        // No locale prefix + URL needs one → add prefix.
        // Two cases:
        //   - active locale is non-default (URL must show /xx)
        //   - hide_default_locale is off (default locale must also show in URL,
        //     e.g. /about → /en/about so every URL has a locale segment)
        if (! $hasLocalePrefix && ($locale !== $default || ! $hideDefault)) {
            return $this->redirectTo($request, $locale.'/'.$path);
        }

        return $next($request);
    }

    protected function redirectTo(Request $request, string $path): Response
    {
        $url = url('/'.ltrim($path, '/'));
        $query = $request->getQueryString();

        return redirect($query ? "{$url}?{$query}" : $url);
    }
}
