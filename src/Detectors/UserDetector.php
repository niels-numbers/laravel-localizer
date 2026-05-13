<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Detectors;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use NielsNumbers\LaravelLocalizer\Contracts\DetectorInterface;

class UserDetector implements DetectorInterface
{
    public function detect(Request $request): ?string
    {
        // `locale` is not part of the framework's User contract. Read
        // it via the Eloquent attribute accessor so any model that
        // exposes the column (or a custom accessor) works without
        // committing to a concrete user class.
        $locale = Auth::user()?->getAttribute('locale');

        return is_string($locale) ? $locale : null;
    }
}
