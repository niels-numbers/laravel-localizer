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
 * Documents the runtime default-locale override (multitenancy use case):
 * `defaultLocale()` defaults to `config('app.fallback_locale')` and can
 * be overridden per request via `setActiveDefaultLocale()` — useful when
 * different tenants/domains share one process but have different default
 * languages, and `hide_default_locale` should follow the *tenant's*
 * default (not the global config one).
 */
class ActiveDefaultLocaleTest extends TestCase
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
        app(Localizer::class)->setActiveDefaultLocale(null);
        parent::tearDown();
    }

    public function test_default_locale_falls_back_to_app_fallback_locale()
    {
        $this->assertSame('en', app(Localizer::class)->defaultLocale());
    }

    public function test_set_active_default_locale_overrides_config()
    {
        $localizer = app(Localizer::class);
        $localizer->setActiveDefaultLocale('de');

        $this->assertSame('de', $localizer->defaultLocale());
        // Underlying config is untouched — no leak into Laravel's
        // translation fallback or other consumers.
        $this->assertSame('en', Config::get('app.fallback_locale'));
    }

    public function test_set_active_default_locale_null_resets_to_config()
    {
        $localizer = app(Localizer::class);
        $localizer->setActiveDefaultLocale('de');
        $localizer->setActiveDefaultLocale(null);

        $this->assertSame('en', $localizer->defaultLocale());
    }

    public function test_url_generator_picks_unprefixed_variant_for_overridden_default()
    {
        Route::localize(function () {
            Route::get('/about', fn () => 'about')->name('about');
        });

        // Tenant default = de. App locale also = de (set by tenant middleware).
        // route('about') should resolve to /about (not /de/about), because
        // the tenant's default locale should be the hidden one.
        app(Localizer::class)->setActiveDefaultLocale('de');
        App::setLocale('de');

        $this->assertSame(url('/about'), route('about'));
    }

    public function test_url_generator_keeps_config_default_prefixed_when_overridden()
    {
        Route::localize(function () {
            Route::get('/about', fn () => 'about')->name('about');
        });

        // Tenant default = de. Building a switcher link to en should now
        // emit the prefix — en is no longer the hidden default for this
        // tenant.
        app(Localizer::class)->setActiveDefaultLocale('de');
        App::setLocale('en');

        $this->assertSame(url('/en/about'), route('about'));
    }

    public function test_redirect_locale_strips_overridden_default_prefix()
    {
        // Tenant default = de, hide_default on. /de/foo should now strip to
        // /foo (where the global config default 'en' would normally not
        // strip a /de prefix).
        app(Localizer::class)->setActiveDefaultLocale('de');
        App::setLocale('de');

        Route::group(['middleware' => RedirectLocale::class, 'locale_type' => 'with_locale'], function () {
            Route::get('/{locale}/foo', fn () => 'foo');
            Route::get('/foo', fn () => 'foo');
        });

        $this->get('/de/foo')->assertRedirect('/foo');
    }

    public function test_redirect_locale_does_not_strip_config_default_when_overridden()
    {
        // Tenant default = de. Hitting /en/foo should NOT strip the /en
        // prefix (en is no longer the hidden default for this tenant) —
        // /en/foo is a valid prefixed URL that should pass through.
        app(Localizer::class)->setActiveDefaultLocale('de');
        App::setLocale('en');

        Route::group(['middleware' => RedirectLocale::class, 'locale_type' => 'with_locale'], function () {
            Route::get('/{locale}/foo', fn () => 'foo');
            Route::get('/foo', fn () => 'foo');
        });

        $this->get('/en/foo')->assertOk();
    }

    public function test_set_locale_falls_back_to_overridden_default()
    {
        // No URL/session/cookie/detector signal → SetLocale falls back to
        // the package's default. With the override in place, that's the
        // tenant default, not config('app.fallback_locale').
        app(Localizer::class)->setActiveDefaultLocale('de');

        Route::group(['middleware' => SetLocale::class, 'locale_type' => 'with_locale'], function () {
            Route::get('/about', fn () => App::getLocale())->name('about.bare');
        });

        $response = $this->get('/about');
        $response->assertOk();
        $this->assertSame('de', App::getLocale());
    }
}
