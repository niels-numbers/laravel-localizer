<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Tests\Feature\Macros;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use NielsNumbers\LaravelLocalizer\Facades\Localizer;
use NielsNumbers\LaravelLocalizer\Middleware\SetLocale;
use NielsNumbers\LaravelLocalizer\ServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Regression tests for the Route::translate() locale-detection bug:
 * translated routes use literal locale prefixes (/de, /fr) rather than a
 * {locale} placeholder, so SetLocale couldn't read the locale from the URL
 * and App::getLocale() stayed on the default for any request with empty
 * session and cookie. Lives in its own test class because TranslateMacroTest
 * partial-mocks the Localizer facade, and these tests need the real service
 * (config-driven supportedLocales/hideDefaultLocale and the URI translator).
 */
class TranslateMacroLocaleDetectionTest extends TestCase
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
    }

    protected function setUp(): void
    {
        parent::setUp();

        Lang::addLines(['routes.team' => 'team'], 'en');
        Lang::addLines(['routes.team' => 'mannschaft'], 'de');
        Lang::addLines(['routes.team' => 'equipe'], 'fr');
    }

    public function test_translated_route_sets_app_locale_from_url()
    {
        Route::middleware(SetLocale::class)->group(function () {
            Route::translate(function () {
                Route::get(Localizer::url('team'), fn () => App::getLocale())->name('team');
            });
        });

        $this->get('/de/mannschaft')->assertOk()->assertSeeText('de');
        $this->get('/fr/equipe')->assertOk()->assertSeeText('fr');
        $this->get('/team')->assertOk()->assertSeeText('en');
    }

    public function test_translated_route_does_not_fall_through_to_session()
    {
        // With session=de but URL=/team (= en, default + hide_default), the
        // URL must win — same priority as for LocalizeMacro `with_locale.*`
        // routes. Without the action-attribute lookup in SetLocale, the
        // session value would leak through.
        Config::set('localizer.persist_locale.session', true);

        Route::middleware(SetLocale::class)->group(function () {
            Route::translate(function () {
                Route::get(Localizer::url('team'), fn () => App::getLocale())->name('team');
            });
        });

        $this->withSession(['locale' => 'de'])
            ->get('/team')
            ->assertOk()
            ->assertSeeText('en');
    }
}
