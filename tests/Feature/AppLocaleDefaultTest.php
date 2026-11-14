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
 * The default locale is `app.locale` as configured, independent of
 * `app.fallback_locale` (Laravel's translation fallback).
 */
class AppLocaleDefaultTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        Config::set('app.locale', 'fa');
        Config::set('app.fallback_locale', 'en');
        Config::set('localizer.supported_locales', ['fa', 'en', 'ar']);
        Config::set('localizer.hide_default_locale', true);
        Config::set('localizer.persist_locale.session', false);
        Config::set('localizer.persist_locale.cookie', false);
        Config::set('localizer.detectors', []);
    }

    public function test_default_locale_is_app_locale()
    {
        $this->assertSame('fa', app(Localizer::class)->defaultLocale());
    }

    public function test_default_locale_survives_set_locale()
    {
        App::setLocale('ar');

        $this->assertSame('fa', app(Localizer::class)->defaultLocale());
    }

    public function test_unprefixed_url_serves_default_locale()
    {
        Route::middleware(SetLocale::class)->group(function () {
            Route::localize(function () {
                Route::get('/about', fn () => App::getLocale())->name('about');
            });
        });

        $this->get('/about')->assertSeeText('fa');
        $this->get('/ar/about')->assertSeeText('ar');
        // Default unchanged after a request in another locale.
        $this->get('/about')->assertSeeText('fa');
    }

    public function test_url_generator_hides_default_locale_prefix()
    {
        Route::localize(function () {
            Route::get('/about', fn () => 'about')->name('about');
        });

        App::setLocale('fa');
        $this->assertSame(url('/about'), route('about'));

        App::setLocale('en');
        $this->assertSame(url('/en/about'), route('about'));
    }

    public function test_translate_registers_unprefixed_variant_for_default_locale()
    {
        Route::translate(function () {
            Route::get('about', fn () => App::getLocale())->name('about');
        });
        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName('without_locale.about');

        $this->assertSame('about', $route->uri());
        $this->assertSame('fa', $route->getAction('locale'));
    }

    public function test_translation_fallback_stays_app_fallback_locale()
    {
        app('translator')->addLines(['messages.hello' => 'Hello'], 'en');
        app('translator')->addLines(['messages.hello' => 'Salam'], 'fa');

        App::setLocale('ar');

        $this->assertSame('Hello', __('messages.hello'));
    }
}
