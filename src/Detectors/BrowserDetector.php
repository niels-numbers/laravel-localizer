<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Detectors;

use CodeZero\BrowserLocale\BrowserLocale;
use CodeZero\BrowserLocale\Filters\CombinedFilter;
use Illuminate\Http\Request;
use NielsNumbers\LaravelLocalizer\Contracts\DetectorInterface;

class BrowserDetector implements DetectorInterface
{
    /**
     * @return string|array<int, string>|null
     */
    public function detect(Request $request): string|array|null
    {
        $header = $request->header('Accept-Language');

        if (! $header) {
            return null;
        }

        $locales = (new BrowserLocale($header))->filter(new CombinedFilter);

        return $locales ?: null;
    }
}
