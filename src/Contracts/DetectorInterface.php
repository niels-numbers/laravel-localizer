<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Contracts;

use Illuminate\Http\Request;

interface DetectorInterface
{
    /**
     * Return the detected locale, an ordered list of locale candidates,
     * or null if no locale could be detected.
     *
     * @return string|array<int, string>|null
     */
    public function detect(Request $request): string|array|null;
}
