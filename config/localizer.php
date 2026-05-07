<?php

declare(strict_types=1);

return [
    'supported_locales' => [], // ['de', 'en', .. ]

    'hide_default_locale' => true,

    'redirect_enabled' => true,

    'persist_locale' => [
        'session' => true,
        'cookie' => true,
    ],

    'detectors' => [
        NielsNumbers\LaravelLocalizer\Detectors\UserDetector::class,
        NielsNumbers\LaravelLocalizer\Detectors\BrowserDetector::class,
    ],
];
