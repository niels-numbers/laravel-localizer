<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Tests\Feature\Macros;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use NielsNumbers\LaravelLocalizer\Exceptions\UnnamedTranslatedRouteException;
use NielsNumbers\LaravelLocalizer\Facades\Localizer;
use NielsNumbers\LaravelLocalizer\ServiceProvider;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class TranslateMacroTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.fallback_locale', 'en');

        Localizer::shouldReceive('supportedLocales')->andReturn(['en', 'de']);
        Localizer::shouldReceive('hideDefaultLocale')->andReturn(true);
    }

    public function test_registers_routes_for_each_locale()
    {
        Route::translate(function () {
            Route::get('about', fn () => 'ok')->name('about');
        });

        $routes = collect(Route::getRoutes())->pluck('action.as');

        $this->assertTrue($routes->contains('translated_en.about'));
        $this->assertTrue($routes->contains('translated_de.about'));
        $this->assertTrue($routes->contains('without_locale.about'));
    }

    public function test_unnamed_route_throws()
    {
        $this->expectException(UnnamedTranslatedRouteException::class);

        Route::translate(function () {
            Route::get('about', fn () => 'ok');
        });
    }

    public function test_unnamed_route_error_names_the_offending_uri()
    {
        $this->expectExceptionMessageMatches('/about/');

        Route::translate(function () {
            Route::get('about', fn () => 'ok');
        });
    }

    public function test_restores_locale_after_registration()
    {
        App::setLocale('fr');

        Route::translate(fn () => null);

        $this->assertEquals('fr', App::getLocale());
    }

    public function test_restores_locale_when_closure_throws()
    {
        App::setLocale('fr');

        try {
            Route::translate(function () {
                throw new RuntimeException('boom');
            });
            $this->fail('expected exception was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertEquals('fr', App::getLocale(),
            'App locale must be restored even when route registration throws.');
    }

    public function test_inner_middleware_group_propagates_into_translated_routes()
    {
        Route::translate(function () {
            Route::middleware('auth')->group(function () {
                Route::get('about', fn () => 'ok')->name('about');
            });
        });
        Route::getRoutes()->refreshNameLookups();

        foreach (['translated_en.about', 'translated_de.about', 'without_locale.about'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "route {$name} should be registered");
            $this->assertContains('auth', $route->middleware());
        }
    }

    public function test_translated_routes_carry_locale_action_attribute()
    {
        // SetLocale recovers the locale from this attribute for translated
        // routes (no {locale} URL parameter to read).
        Route::translate(function () {
            Route::get('about', fn () => 'ok')->name('about');
        });
        Route::getRoutes()->refreshNameLookups();

        $this->assertSame('en', Route::getRoutes()->getByName('translated_en.about')->getAction('locale'));
        $this->assertSame('de', Route::getRoutes()->getByName('translated_de.about')->getAction('locale'));
        $this->assertSame('en', Route::getRoutes()->getByName('without_locale.about')->getAction('locale'));
    }
}
