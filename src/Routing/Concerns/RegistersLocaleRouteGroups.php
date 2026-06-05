<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Routing\Concerns;

use Closure;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;
use NielsNumbers\LaravelLocalizer\Exceptions\UnnamedTranslatedRouteException;

trait RegistersLocaleRouteGroups
{
    /**
     * Register a route group, then unname any route that contributed no name
     * of its own.
     *
     * Laravel applies a group's `as` attribute to every route in the group, so
     * a route registered without `->name()` ends up named with the bare prefix
     * (e.g. "with_locale."). We don't want that: an unnamed route should stay
     * unnamed - reachable by URL, absent from the name lookup - instead of
     * silently colliding with every other unnamed route under the same prefix.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function groupKeepingUnnamedRoutesUnnamed(array $attributes, Closure $closure): void
    {
        $unnamed = $this->routesAddedWithoutOwnName($attributes, $closure);

        foreach ($unnamed as $route) {
            $action = $route->getAction();
            unset($action['as']);
            $route->setAction($action);
        }

        if ($unnamed !== []) {
            Route::getRoutes()->refreshNameLookups();
        }
    }

    /**
     * Register a route group, but reject any route that contributed no name of
     * its own.
     *
     * Translated routes have locale-specific URIs and rely on a shared name to
     * resolve the equivalent URL in another locale (see
     * UnnamedTranslatedRouteException). An unnamed translated route can never be
     * language-switched, so we fail fast at registration instead of letting it
     * blow up later inside Route::localizedUrl().
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function groupRequiringNamedRoutes(array $attributes, Closure $closure): void
    {
        $unnamed = $this->routesAddedWithoutOwnName($attributes, $closure);

        if ($unnamed !== []) {
            throw UnnamedTranslatedRouteException::forRoute($unnamed[0]);
        }
    }

    /**
     * Run the group and return the routes it added that set no name of their
     * own - i.e. whose accumulated name is exactly the group's `as` prefix.
     *
     * Route::getRoutes() returns the whole app collection, so we snapshot it by
     * object identity before the group runs and diff afterwards to isolate the
     * routes this closure registered.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<RouteInstance>
     */
    private function routesAddedWithoutOwnName(array $attributes, Closure $closure): array
    {
        $routeCollection = Route::getRoutes();

        $preExisting = [];
        foreach ($routeCollection->getRoutes() as $route) {
            $preExisting[spl_object_id($route)] = true;
        }

        Route::group($attributes, $closure);

        $prefix = $attributes['as'] ?? null;
        if ($prefix === null) {
            return [];
        }

        $unnamed = [];
        foreach ($routeCollection->getRoutes() as $route) {
            if (isset($preExisting[spl_object_id($route)])) {
                continue; // not added by this group - leave it untouched
            }

            if ($route->getName() === $prefix) {
                $unnamed[] = $route;
            }
        }

        return $unnamed;
    }
}
