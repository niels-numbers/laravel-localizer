<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Tests\Feature\Detectors;

use Illuminate\Http\Request;
use NielsNumbers\LaravelLocalizer\Detectors\BrowserDetector;
use Orchestra\Testbench\TestCase;

class BrowserDetectorTest extends TestCase
{
    public function test_returns_null_when_no_accept_language_header_is_present()
    {
        $request = Request::create('/');
        $request->headers->remove('Accept-Language');

        $this->assertNull((new BrowserDetector)->detect($request));
    }

    public function test_returns_ordered_locale_candidates_from_accept_language()
    {
        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9,en;q=0.8',
        ]);

        $result = (new BrowserDetector)->detect($request);

        $this->assertIsArray($result);
        $this->assertSame(['de-DE', 'de', 'en'], $result);
    }

    public function test_orders_by_quality_weight()
    {
        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_ACCEPT_LANGUAGE' => 'fr;q=0.5,en;q=0.9,de;q=0.8',
        ]);

        $result = (new BrowserDetector)->detect($request);

        $this->assertSame(['en', 'de', 'fr'], $result);
    }
}
