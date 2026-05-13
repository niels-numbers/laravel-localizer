<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator as UrlGeneratorContract;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use NielsNumbers\LaravelLocalizer\Facades\Localizer as LocalizerFacade;
use NielsNumbers\LaravelLocalizer\Illuminate\Routing\UrlGenerator;
use NielsNumbers\LaravelLocalizer\Macros\LocalizeMacro;
use NielsNumbers\LaravelLocalizer\Macros\TranslateMacro;
use NielsNumbers\LaravelLocalizer\Services\CurrentRouteLocalizer;
use NielsNumbers\LaravelLocalizer\Services\LocaleDirection;
use NielsNumbers\LaravelLocalizer\Services\UriTranslator;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/localizer.php' => config_path('localizer.php'),
        ], 'config');

        $this->registerMacros();
    }

    public function register(): void
    {
        $this->app->singleton(Localizer::class, fn () => new Localizer(new UriTranslator, new LocaleDirection));
        $this->mergeConfigFrom(__DIR__.'/../config/localizer.php', 'localizer');

        $this->registerUrlGenerator();
    }

    protected function registerUrlGenerator(): void
    {
        $this->app->singleton('url', function ($app) {
            $routes = $app['router']->getRoutes();

            // The URL generator needs the route collection that exists on the router.
            // Keep in mind this is an object, so we're passing by references here
            // and all the registered routes will be available to the generator.
            $app->instance('routes', $routes);

            return new UrlGenerator(
                $routes,
                $app->rebinding(
                    'request', $this->requestRebinder()
                ),
                $app['config']['app.asset_url']
            );
        });

        $this->app->extend('url', function (UrlGeneratorContract $url, $app) {
            // Next we will set a few service resolvers on the URL generator so it can
            // get the information it needs to function. This just provides some of
            // the convenience features to this URL generator like "signed" URLs.
            $url->setSessionResolver(function () {
                return $this->app['session'] ?? null;
            });

            $url->setKeyResolver(function () {
                return $this->app->make('config')->get('app.key');
            });

            // If the route collection is "rebound", for example, when the routes stay
            // cached for the application, we will need to rebind the routes on the
            // URL generator instance so it has the latest version of the routes.
            $app->rebinding('routes', function ($app, $routes) {
                $app['url']->setRoutes($routes);
            });

            return $url;
        });
    }

    protected function requestRebinder(): Closure
    {
        return function ($app, $request) {
            $app['url']->setRequest($request);
        };
    }

    protected function registerMacros(): void
    {
        $macroRegisterName = LocalizerFacade::macroRegisterName();

        Route::macro($macroRegisterName, function (Closure $closure) {
            App::make(LocalizeMacro::class)->register($closure);
        });

        Route::macro('translate', function (Closure $closure) {
            app(TranslateMacro::class)->register($closure);
        });

        Route::macro('localizedUrl', function (string $locale, bool $absolute = true) {
            return app(CurrentRouteLocalizer::class)->localize($locale, $absolute);
        });

        // Like localizedUrl(), but always emits a prefixed URL — even for
        // target = default-locale with hide_default_locale on. Use this for
        // language switchers: the prefix carries the locale to SetLocale on
        // the next request, and RedirectLocale strips it to the canonical
        // form. localizedUrl() stays canonical for hreflang/sitemaps.
        Route::macro('localizedSwitcherUrl', function (string $locale, bool $absolute = true) {
            return app(CurrentRouteLocalizer::class)->localize($locale, $absolute, true);
        });

        // Has any localized variant of this route name been registered?
        // Refresh the name lookups first — Laravel only rebuilds them lazily
        // during request matching, so fresh registrations aren't visible to
        // Route::has() outside an HTTP request (e.g. when called from boot
        // code or asserted in tests).
        Route::macro('hasLocalized', function (string $name): bool {
            Route::getRoutes()->refreshNameLookups();

            if (Route::has('with_locale.'.$name) || Route::has('without_locale.'.$name)) {
                return true;
            }

            foreach (LocalizerFacade::supportedLocales() as $locale) {
                if (Route::has("translated_$locale.$name")) {
                    return true;
                }
            }

            return false;
        });

        // Strip the localizer prefix from this Route's name. Lets calling
        // code keep `$route->baseName() === 'about'` checks intact even
        // though the macros register the route as `with_locale.about` /
        // `translated_de.about` etc. — see docs/template-helpers.md.
        \Illuminate\Routing\Route::macro('baseName', function (): ?string {
            /** @var \Illuminate\Routing\Route $this */
            return LocalizerFacade::baseName($this->getName());
        });

        // Convenience: `Route::current()?->baseName()` without the null
        // dance. Returns null outside of a request, same as Route::current().
        // Calls the LocalizerFacade directly instead of dispatching through
        // the `baseName` macro on the Route instance - same result, but
        // avoids a static-analysis dead-end where PHPStan/Larastan don't
        // see the per-instance macro.
        Route::macro('currentBaseName', function (): ?string {
            return LocalizerFacade::baseName(Route::current()?->getName());
        });

        // Is the current request handled by a localizer-managed route?
        Route::macro('isLocalized', function (): bool {
            $current = Route::current();
            if ($current === null) {
                return false;
            }

            $name = $current->getName() ?? '';

            return Str::startsWith(
                $name,
                ['with_locale.', 'without_locale.', 'translated_']
            );
        });
    }
}
