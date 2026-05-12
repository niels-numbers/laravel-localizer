<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use NielsNumbers\LaravelLocalizer\Middleware\RedirectLocale;
use NielsNumbers\LaravelLocalizer\Middleware\SetLocale;
use NielsNumbers\LaravelLocalizer\ServiceProvider;
use Orchestra\Testbench\TestCase;

class LifecycleTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        Config::set('app.locale', 'en');
        Config::set('app.fallback_locale', 'en');
        Config::set('localizer.supported_locales', ['en', 'de']);
        Config::set('localizer.hide_default_locale', true);
        Config::set('localizer.persist_locale.session', false);
        Config::set('localizer.persist_locale.cookie', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Log::swap(new \Illuminate\Log\Logger(
            new Logger('null', [new NullHandler])
        ));
    }

    protected function defineRoutes($router)
    {
        $router->middleware([SetLocale::class, RedirectLocale::class])->group(function () {
            Route::localize(function () {
                Route::get('/about', fn () => route('about', [], false))->name('about');
                Route::get('/contact', fn () => route('about', ['locale' => 'en'], false))->name('contact');
                // Echoes the in-controller App locale. Used to verify that
                // SetLocale set the locale correctly even for non-safe methods
                // (which RedirectLocale skips to preserve the request body).
                Route::post('/save', fn () => app()->getLocale())->name('save');
            });

            // Plain unlocalized route inside the same middleware group:
            // both middlewares must skip it (no `locale_type` action).
            // Reports the in-controller App locale so we can confirm
            // SetLocale didn't touch it either.
            Route::get('/admin', fn () => app()->getLocale())->name('admin');
        });
    }

    public function test_localized_request_resolves_and_handler_generates_matching_url()
    {
        $response = $this->get('/de/about');

        $response->assertOk();
        $response->assertSee('/de/about');
    }

    public function test_default_locale_request_drops_prefix_in_generated_urls()
    {
        $response = $this->get('/about');

        $response->assertOk();
        $response->assertSee('/about');
        $response->assertDontSee('/en/about');
    }

    public function test_visiting_default_locale_with_prefix_redirects_to_unprefixed()
    {
        $response = $this->get('/en/about');

        $response->assertRedirect('/about');
    }

    public function test_visiting_unprefixed_path_with_browser_in_non_default_locale_redirects()
    {
        $response = $this->get('/about', ['Accept-Language' => 'de-DE,de;q=0.9']);

        $response->assertRedirect('/de/about');
    }

    public function test_query_string_is_preserved_through_redirect_chain()
    {
        $response = $this->get('/about?utm_source=newsletter', [
            'Accept-Language' => 'de-DE,de;q=0.9',
        ]);

        $response->assertRedirect('/de/about?utm_source=newsletter');
    }

    public function test_explicit_locale_switch_keeps_prefix_for_target_default_locale()
    {
        // Visiting /de/contact while app is German — handler builds a
        // switch-link to English (the default). With hide_default_locale on,
        // the target route still needs the /en prefix at link-time so SetLocale
        // detects the switch from the URL; RedirectLocale then strips it on
        // the follow-up request.
        $response = $this->get('/de/contact');

        $response->assertOk();
        $response->assertSee('/en/about');
    }

    public function test_unsupported_path_prefix_is_not_treated_as_locale()
    {
        Route::middleware([SetLocale::class, RedirectLocale::class])
            ->get('/xx/page', fn () => 'ok');

        $response = $this->get('/xx/page');

        $response->assertOk();
        $response->assertSee('ok');
    }

    public function test_post_to_localized_url_applies_locale_in_controller()
    {
        // Regression: RedirectLocale skips non-safe methods, so POST /de/save
        // is NOT redirected. SetLocale must still set App::getLocale() to 'de'
        // so anything inside the controller (mailables, translations, route())
        // sees the right locale.
        $response = $this->post('/de/save');

        $response->assertOk();
        $this->assertSame('de', $response->getContent());
    }

    public function test_post_to_default_locale_prefix_applies_default_locale()
    {
        // POST /en/save with hide_default_locale on: no redirect (non-safe
        // method), but App::getLocale() must still be 'en' from the URL.
        $response = $this->post('/en/save');

        $response->assertOk();
        $this->assertSame('en', $response->getContent());
    }

    public function test_post_to_unprefixed_url_uses_browser_locale()
    {
        // POST /save with no URL locale signal: SetLocale falls through to
        // detectors. With Accept-Language: de, App::getLocale() must be 'de'
        // even though RedirectLocale doesn't redirect to /de/save.
        $response = $this->post('/save', [], ['Accept-Language' => 'de-DE,de;q=0.9']);

        $response->assertOk();
        $this->assertSame('de', $response->getContent());
    }

    public function test_unlocalized_route_inside_middleware_group_is_not_touched()
    {
        // Even though SetLocale + RedirectLocale sit on the route, /admin has
        // no `locale_type` action attribute (it wasn't wrapped in
        // Route::localize). Both middlewares must skip it: no redirect, no
        // App::setLocale(). The handler sees the configured app.locale
        // ('en' here), not anything browser-derived.
        $response = $this->get('/admin', ['Accept-Language' => 'de-DE,de;q=0.9']);

        $response->assertOk();
        $this->assertSame('en', $response->getContent());
    }

    public function test_unlocalized_route_is_not_redirected_when_app_locale_is_non_default()
    {
        // RedirectLocale would normally rewrite /admin → /de/admin when the
        // app locale is `de` (non-default + no prefix). For unlocalized
        // routes we skip that — otherwise: 302 to a path that doesn't exist
        // (no /de/admin route registered) → broken UX.
        $this->app->setLocale('de');

        $response = $this->get('/admin');

        // Critical: 200, NOT 302. Body locale comes from the manually-set
        // app locale ('de') because that's what the handler reads — but
        // RedirectLocale didn't try to rewrite the URL.
        $response->assertOk();
        $this->assertNull($response->headers->get('Location'));
    }
}
