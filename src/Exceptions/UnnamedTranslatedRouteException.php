<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Exceptions;

use Illuminate\Routing\Route;
use LogicException;

class UnnamedTranslatedRouteException extends LogicException
{
    public static function forRoute(Route $route): self
    {
        return new self(
            'Every route inside Route::translate() must have a name. The route '.
            "\"/{$route->uri()}\" has none. Translated routes have locale-specific ".
            'URIs (e.g. /about vs. /ueber), so Route::localizedUrl() relies on the '.
            'shared route name to find the equivalent URL in another locale - '.
            'without a name the language switch cannot work. Add ->name() to the route.'
        );
    }
}
