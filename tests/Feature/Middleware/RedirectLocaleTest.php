<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Tests\Feature\Middleware;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use NielsNumbers\LaravelLocalizer\Middleware\RedirectLocale;
use NielsNumbers\LaravelLocalizer\Middleware\SetLocale;
use NielsNumbers\LaravelLocalizer\ServiceProvider;
use Orchestra\Testbench\TestCase;

class RedirectLocaleTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('app.locale', 'en');

        // RedirectLocale skips routes without `locale_type` (so plain
        // unlocalized routes coexist in the same middleware group).
        // Tag these test routes — Route::localize() does this for free.
        Route::group([
            'middleware' => RedirectLocale::class,
            'locale_type' => 'with_locale',
        ], function () {
            Route::get('/{locale}/about', fn () => 'ok');
            Route::get('/about', fn () => 'ok');
        });

        // Logs create permission conflicts in docker
        Log::swap(new \Illuminate\Log\Logger(
            new Logger('null', [new NullHandler])
        ));
    }

    protected function defineEnvironment($app)
    {
        Config::set('app.fallback_locale', 'en');
        Config::set('localizer.supported_locales', ['en', 'de', 'pt-BR']);
    }

    public function test_redirects_default_locale_when_hidden()
    {
        App::setLocale('en');
        Config::set('localizer.hide_default_locale', true);

        $response = $this->get('/en/about');
        $response->assertRedirect('/about');
    }

    public function test_redirects_to_prefixed_locale_when_missing()
    {
        App::setLocale('de');
        Config::set('localizer.hide_default_locale', true);

        $response = $this->get('/about');
        $response->assertRedirect('/de/about');
    }

    public function test_no_redirect_when_disabled()
    {
        App::setLocale('en');
        Config::set('localizer.redirect_enabled', false);

        $response = $this->get('/about');
        $response->assertOk();
    }

    public function test_does_not_treat_unsupported_two_letter_prefix_as_locale()
    {
        App::setLocale('en');
        Config::set('localizer.hide_default_locale', true);

        Route::group(['middleware' => RedirectLocale::class, 'locale_type' => 'with_locale'],
            fn () => Route::get('/xx/about', fn () => 'ok'));

        $response = $this->get('/xx/about');
        $response->assertOk();
    }

    public function test_handles_multi_character_locale_prefix()
    {
        App::setLocale('pt-BR');
        Config::set('app.fallback_locale', 'pt-BR');
        Config::set('localizer.hide_default_locale', true);

        $response = $this->get('/pt-BR/about');
        $response->assertRedirect('/about');
    }

    public function test_preserves_query_string_when_stripping_default_prefix()
    {
        App::setLocale('en');
        Config::set('localizer.hide_default_locale', true);

        // Symfony's getQueryString() returns parameters sorted alphabetically.
        $response = $this->get('/en/about?utm_source=newsletter&ref=foo');
        $response->assertRedirect('/about?ref=foo&utm_source=newsletter');
    }

    public function test_post_to_default_prefix_does_not_redirect()
    {
        // A 302 from POST gets converted to GET by browsers, dropping the
        // request body. Non-safe methods must reach the controller directly.
        App::setLocale('en');
        Config::set('localizer.hide_default_locale', true);

        Route::group(['middleware' => RedirectLocale::class, 'locale_type' => 'with_locale'], function () {
            Route::post('/{locale}/about', fn () => 'ok');
            Route::post('/about', fn () => 'ok');
        });

        $response = $this->post('/en/about');
        $response->assertOk();
        $this->assertSame('ok', $response->getContent());
    }

    public function test_post_to_unprefixed_with_non_default_locale_does_not_redirect()
    {
        App::setLocale('de');
        Config::set('localizer.hide_default_locale', true);

        Route::group(['middleware' => RedirectLocale::class, 'locale_type' => 'with_locale'], function () {
            Route::post('/{locale}/save', fn () => 'ok');
            Route::post('/save', fn () => 'ok');
        });

        $response = $this->post('/save');
        $response->assertOk();
        $this->assertSame('ok', $response->getContent());
    }

    public function test_put_patch_delete_are_also_not_redirected()
    {
        App::setLocale('en');
        Config::set('localizer.hide_default_locale', true);

        Route::group(['middleware' => RedirectLocale::class, 'locale_type' => 'with_locale'], function () {
            Route::put('/{locale}/r', fn () => 'put');
            Route::patch('/{locale}/r', fn () => 'patch');
            Route::delete('/{locale}/r', fn () => 'delete');
        });

        $this->put('/en/r')->assertOk();
        $this->patch('/en/r')->assertOk();
        $this->delete('/en/r')->assertOk();
    }

    public function test_preserves_query_string_when_adding_prefix()
    {
        App::setLocale('de');
        Config::set('localizer.hide_default_locale', true);

        $response = $this->get('/about?utm_source=newsletter');
        $response->assertRedirect('/de/about?utm_source=newsletter');
    }

    public function test_redirects_default_locale_to_prefixed_when_hide_default_locale_is_off()
    {
        // With hide_default_locale: false, every URL must carry a locale
        // segment - including the default. /about should redirect to /en/about.
        App::setLocale('en');
        Config::set('localizer.hide_default_locale', false);

        $response = $this->get('/about');
        $response->assertRedirect('/en/about');
    }

    public function test_keeps_prefixed_default_locale_when_hide_default_locale_is_off()
    {
        // The other side of the same coin: /en/about must NOT redirect to
        // /about when hide_default_locale is off.
        App::setLocale('en');
        Config::set('localizer.hide_default_locale', false);

        $response = $this->get('/en/about');
        $response->assertOk();
    }

    public function test_wrong_case_locale_prefix_redirects_to_canonical_with_localize_macro()
    {
        // Stale email links sometimes send users to /EN/about. The route
        // constraint in LocalizeMacro is case-insensitive, so the route
        // matches and this middleware redirects to the canonical lowercase
        // form. Without the case-insensitive constraint, the route would
        // not match and the request would 404.
        App::setLocale('en');
        Config::set('localizer.hide_default_locale', true);

        Route::middleware([SetLocale::class, RedirectLocale::class])
            ->group(function () {
                Route::localize(function () {
                    Route::get('/about', fn () => 'ok')->name('about');
                });
            });

        // Default locale + hide_default_locale on → strip the prefix entirely.
        $this->get('/EN/about')->assertRedirect('/about');

        // Non-default locale → canonicalize the case but keep the prefix.
        $this->get('/DE/about')->assertRedirect('/de/about');
    }

    public function test_wrong_case_locale_prefix_redirects_to_canonical_with_hide_default_off()
    {
        App::setLocale('en');
        Config::set('localizer.hide_default_locale', false);

        Route::middleware([SetLocale::class, RedirectLocale::class])
            ->group(function () {
                Route::localize(function () {
                    Route::get('/about', fn () => 'ok')->name('about');
                });
            });

        // Default locale + hide_default_locale off → keep the prefix, just
        // canonicalize the case.
        $this->get('/EN/about')->assertRedirect('/en/about');
    }
}
