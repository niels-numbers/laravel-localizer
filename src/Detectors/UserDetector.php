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
        return Auth::user()?->locale ?? null;
    }
}
