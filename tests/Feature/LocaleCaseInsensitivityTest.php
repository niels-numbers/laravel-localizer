<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Tests\Feature;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use NielsNumbers\LaravelLocalizer\Localizer;
use NielsNumbers\LaravelLocalizer\Middleware\SetLocale;
use NielsNumbers\LaravelLocalizer\ServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Documents the case-insensitive handling of locale values.
 * App code (especially legacy DB columns) often stores locale codes
 * in uppercase ('EN', 'DE'). The package normalizes these against
 * the canonical lowercase form configured in supported_locales.
 */
class LocaleCaseInsensitivityTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        Config::set('app.locale', 'en');
        Config::set('app.fallback_locale', 'en');
        Config::set('localizer.supported_locales', ['en', 'de', 'fr']);
        Config::set('localizer.hide_default_locale', true);
        Config::set('localizer.persist_locale.session', false);
        Config::set('localizer.persist_locale.cookie', false);
        Config::set('localizer.detectors', []);
    }

    public function test_canonicalize_returns_canonical_form_for_uppercase()
    {
        $localizer = app(Localizer::class);

        $this->assertSame('en', $localizer->canonicalize('EN'));
        $this->assertSame('de', $localizer->canonicalize('DE'));
        $this->assertSame('de', $localizer->canonicalize('De'));
    }

    public function test_canonicalize_returns_canonical_form_for_lowercase()
    {
        $this->assertSame('en', app(Localizer::class)->canonicalize('en'));
    }

    public function test_canonicalize_passes_through_unsupported_values()
    {
        // Pass-through preserves existing behavior for callers that
        // intentionally use locales not in supported_locales.
        $this->assertSame('klingon', app(Localizer::class)->canonicalize('klingon'));
    }

    public function test_canonicalize_returns_null_for_null()
    {
        $this->assertNull(app(Localizer::class)->canonicalize(null));
    }

    public function test_is_supported_is_case_insensitive()
    {
        $localizer = app(Localizer::class);

        $this->assertTrue($localizer->isSupported('EN'));
        $this->assertTrue($localizer->isSupported('De'));
        $this->assertFalse($localizer->isSupported('KLINGON'));
    }

    public function test_is_active_is_case_insensitive()
    {
        $localizer = app(Localizer::class);

        $this->assertTrue($localizer->isActive('EN'));
        $this->assertTrue($localizer->isActive('De'));
        $this->assertFalse($localizer->isActive('KLINGON'));
    }

    public function test_url_generator_normalizes_uppercase_app_locale()
    {
        // Simulates User::sendPasswordResetNotification() doing
        // App::setLocale('DE') with an uppercase DB value.
        App::setLocale('DE');

        Route::get('/{locale}/about', fn () => 'ok')
            ->where('locale', 'en|de|fr')
            ->name('with_locale.about');
        Route::get('/about', fn () => 'ok')->name('without_locale.about');

        $this->assertSame('/de/about', app('url')->route('about', [], false));
    }

    public function test_url_generator_hides_default_when_app_locale_is_uppercase_default()
    {
        App::setLocale('EN');

        Route::get('/{locale}/about', fn () => 'ok')
            ->where('locale', 'en|de|fr')
            ->name('with_locale.about');
        Route::get('/about', fn () => 'ok')->name('without_locale.about');

        // hide_default_locale is on, App locale normalizes to 'en' (= default)
        // → unprefixed variant.
        $this->assertSame('/about', app('url')->route('about', [], false));
    }

    public function test_url_generator_normalizes_uppercase_locale_parameter()
    {
        Route::get('/{locale}/about', fn () => 'ok')
            ->where('locale', 'en|de|fr')
            ->name('with_locale.about');
        Route::get('/about', fn () => 'ok')->name('without_locale.about');

        $this->assertSame(
            '/de/about',
            app('url')->route('about', ['locale' => 'DE'], false)
        );
    }

    public function test_set_locale_middleware_stores_canonical_form()
    {
        // Tag the route so SetLocale engages, and route-bind {locale}
        // case-insensitively (LocalizeMacro does the same in production).
        Route::group(['middleware' => SetLocale::class, 'locale_type' => 'with_locale'], function () {
            Route::get('/{locale}/probe', fn () => App::getLocale())
                ->where('locale', '[A-Za-z]{2,5}(?:-[A-Za-z]{2,4})?');
        });

        // Hitting an uppercase URL must still result in App::getLocale()
        // returning the canonical lowercase form, otherwise __('...') would
        // miss the lang/de/* files in subsequent requests / mailables.
        $this->get('/DE/probe')->assertOk()->assertSee('de');
        $this->assertSame('de', App::getLocale());
    }
}
