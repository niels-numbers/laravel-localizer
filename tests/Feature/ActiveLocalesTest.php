<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Tests\Feature;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use NielsNumbers\LaravelLocalizer\Localizer;
use NielsNumbers\LaravelLocalizer\Middleware\RedirectLocale;
use NielsNumbers\LaravelLocalizer\Middleware\SetLocale;
use NielsNumbers\LaravelLocalizer\ServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Documents the runtime active-locales subset (multitenancy use case):
 * `supportedLocales()` is the static boot-time union (drives route
 * registration). `activeLocales()` is what the user is allowed to reach
 * in the current request — defaults to supported, can be narrowed via
 * `Localizer::setActiveLocales([...])` from a custom middleware.
 */
class ActiveLocalesTest extends TestCase
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

    protected function tearDown(): void
    {
        // Localizer is a container singleton; the override survives the request.
        // Reset between tests so cases don't leak state.
        app(Localizer::class)->setActiveLocales(null);
        parent::tearDown();
    }

    public function test_active_locales_defaults_to_supported_locales()
    {
        $localizer = app(Localizer::class);

        $this->assertSame(['en', 'de', 'fr'], $localizer->activeLocales());
        $this->assertTrue($localizer->isActive('en'));
        $this->assertTrue($localizer->isActive('de'));
        $this->assertTrue($localizer->isActive('fr'));
    }

    public function test_set_active_locales_narrows_the_subset()
    {
        $localizer = app(Localizer::class);
        $localizer->setActiveLocales(['en', 'de']);

        $this->assertSame(['en', 'de'], $localizer->activeLocales());
        $this->assertTrue($localizer->isActive('de'));
        $this->assertFalse($localizer->isActive('fr'));

        // supported is unaffected — fr is still a registered route.
        $this->assertTrue($localizer->isSupported('fr'));
    }

    public function test_set_active_locales_null_resets_to_supported()
    {
        $localizer = app(Localizer::class);
        $localizer->setActiveLocales(['en']);
        $localizer->setActiveLocales(null);

        $this->assertSame(['en', 'de', 'fr'], $localizer->activeLocales());
    }

    public function test_is_active_handles_null()
    {
        $this->assertFalse(app(Localizer::class)->isActive(null));
    }

    public function test_set_locale_rejects_supported_but_inactive_route_locale()
    {
        // Tenant only allows en+de; user tries to reach /fr/about.
        Route::group(['middleware' => SetLocale::class, 'locale_type' => 'with_locale'], function () {
            Route::get('/{locale}/about', fn () => App::getLocale())
                ->where('locale', 'en|de|fr')
                ->name('about.locale');
        });

        app(Localizer::class)->setActiveLocales(['en', 'de']);

        $response = $this->get('/fr/about');

        // fr is NOT in active set → SetLocale ignores it and falls back
        // to app.fallback_locale (en).
        $response->assertOk();
        $this->assertSame('en', App::getLocale());
    }

    public function test_set_locale_accepts_active_route_locale()
    {
        Route::group(['middleware' => SetLocale::class, 'locale_type' => 'with_locale'], function () {
            Route::get('/{locale}/about', fn () => App::getLocale())
                ->where('locale', 'en|de|fr')
                ->name('about.locale');
        });

        app(Localizer::class)->setActiveLocales(['en', 'de']);

        $this->get('/de/about')->assertOk();
        $this->assertSame('de', App::getLocale());
    }

    public function test_redirect_locale_treats_inactive_prefix_as_non_locale()
    {
        // supported = en/de/fr, active = en/de.
        // /fr/foo: 'fr' is supported but NOT active. RedirectLocale must
        // treat it as a non-locale path segment (no locale prefix), and
        // since the app locale is `en` (= default, hide_default_locale on),
        // there's no redirect — the request passes through to the route.
        app(Localizer::class)->setActiveLocales(['en', 'de']);
        App::setLocale('en');

        Route::group(['middleware' => RedirectLocale::class, 'locale_type' => 'with_locale'],
            fn () => Route::get('/fr/foo', fn () => 'reached'));

        $response = $this->get('/fr/foo');
        $response->assertOk();
        $this->assertSame('reached', $response->getContent());
    }

    public function test_redirect_locale_strips_active_default_prefix()
    {
        // active = en/de, default = en, hide_default_locale on.
        // /en/foo should still strip to /foo.
        app(Localizer::class)->setActiveLocales(['en', 'de']);
        App::setLocale('en');

        Route::group(['middleware' => RedirectLocale::class, 'locale_type' => 'with_locale'], function () {
            Route::get('/{locale}/foo', fn () => 'foo');
            Route::get('/foo', fn () => 'foo');
        });

        $response = $this->get('/en/foo');
        $response->assertRedirect('/foo');
    }
}
