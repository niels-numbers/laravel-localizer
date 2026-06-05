<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Tests\Feature\Macros;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use NielsNumbers\LaravelLocalizer\Middleware\SetLocale;
use NielsNumbers\LaravelLocalizer\ServiceProvider;
use Orchestra\Testbench\TestCase;

class LocalizeMacroTest extends TestCase
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

    public function test_registers_the_macro()
    {
        Route::localize(function () {
            Route::get('/test', fn () => 'ok')->name('test');
        });

        $routes = collect(Route::getRoutes())->map->getName();

        $this->assertTrue($routes->contains('with_locale.test'));
        $this->assertTrue($routes->contains('without_locale.test'));
    }

    public function test_unnamed_route_stays_unnamed()
    {
        Route::localize(function () {
            Route::get('/about', fn () => 'ok');
        });
        Route::getRoutes()->refreshNameLookups();

        $names = collect(Route::getRoutes())->map->getName();

        // No bare-prefix names leak onto the route.
        $this->assertFalse($names->contains('with_locale.'));
        $this->assertFalse($names->contains('without_locale.'));

        // Both URL variants still exist, just nameless.
        $uris = collect(Route::getRoutes())->map->uri();
        $this->assertTrue($uris->contains('about'));
        $this->assertTrue($uris->contains('{locale}/about'));
    }

    public function test_multiple_unnamed_routes_do_not_collide()
    {
        Route::localize(function () {
            Route::get('/about', fn () => 'about');
            Route::get('/contact', fn () => 'contact');
        });
        Route::getRoutes()->refreshNameLookups();

        $uris = collect(Route::getRoutes())->map->uri();

        // All four variants survive (would collapse if they shared a name).
        $this->assertTrue($uris->contains('about'));
        $this->assertTrue($uris->contains('contact'));
        $this->assertTrue($uris->contains('{locale}/about'));
        $this->assertTrue($uris->contains('{locale}/contact'));
    }

    public function test_named_routes_still_get_prefixed_alongside_unnamed_ones()
    {
        Route::localize(function () {
            Route::get('/about', fn () => 'ok');
            Route::get('/contact', fn () => 'ok')->name('contact');
        });
        Route::getRoutes()->refreshNameLookups();

        $this->assertNotNull(Route::getRoutes()->getByName('with_locale.contact'));
        $this->assertNotNull(Route::getRoutes()->getByName('without_locale.contact'));

        $names = collect(Route::getRoutes())->map->getName();
        $this->assertFalse($names->contains('with_locale.'));
        $this->assertFalse($names->contains('without_locale.'));
    }

    public function test_inner_middleware_group_propagates_into_localized_routes()
    {
        Route::localize(function () {
            Route::middleware('auth')->group(function () {
                Route::get('/profile', fn () => 'ok')->name('profile');
            });
        });
        Route::getRoutes()->refreshNameLookups();

        $with = Route::getRoutes()->getByName('with_locale.profile');
        $without = Route::getRoutes()->getByName('without_locale.profile');

        $this->assertNotNull($with);
        $this->assertNotNull($without);
        $this->assertContains('auth', $with->middleware());
        $this->assertContains('auth', $without->middleware());
    }

    public function test_inner_prefix_group_propagates_into_localized_routes()
    {
        Route::localize(function () {
            Route::prefix('admin')->group(function () {
                Route::get('/dashboard', fn () => 'ok')->name('dashboard');
            });
        });
        Route::getRoutes()->refreshNameLookups();

        $this->assertSame(
            '{locale}/admin/dashboard',
            Route::getRoutes()->getByName('with_locale.dashboard')->uri()
        );
        $this->assertSame(
            'admin/dashboard',
            Route::getRoutes()->getByName('without_locale.dashboard')->uri()
        );
    }

    public function test_unprefixed_route_does_not_match_locale_wildcard()
    {
        // Regression: with a `/` route inside Route::localize(), the
        // with_locale.home pattern is /{locale}. Without a constraint on
        // {locale}, GET /about would match with_locale.home (locale='about')
        // and render the home controller. Constraining {locale} to the
        // supported locales lets /about fall through to without_locale.about.
        Route::middleware(SetLocale::class)->group(function () {
            Route::localize(function () {
                Route::get('/', fn () => 'home')->name('home');
                Route::get('/about', fn () => 'about')->name('about');
            });
        });

        $this->get('/about')->assertOk()->assertSeeText('about');
        $this->get('/')->assertOk()->assertSeeText('home');
        $this->get('/de/about')->assertOk()->assertSeeText('about');
        $this->get('/de')->assertOk()->assertSeeText('home');
    }

    public function test_unsupported_locale_segment_returns_404()
    {
        // /xyz with xyz not in supported_locales used to match with_locale.home
        // (because {locale} accepted anything) and silently render the home
        // route. With the constraint, no route matches → 404.
        Route::middleware(SetLocale::class)->group(function () {
            Route::localize(function () {
                Route::get('/', fn () => 'home')->name('home');
            });
        });

        $this->get('/xyz')->assertNotFound();
    }

    public function test_locale_constraint_uses_configured_supported_locales()
    {
        Route::localize(function () {
            Route::get('/about', fn () => 'ok')->name('about');
        });
        Route::getRoutes()->refreshNameLookups();

        $with = Route::getRoutes()->getByName('with_locale.about');

        // Per-letter char classes give case-insensitive matching that works
        // in both PCRE (PHP) and JS (Ziggy ships this verbatim to the
        // browser). A wrong-case prefix like /EN/about still matches the
        // route and reaches RedirectLocale.
        $this->assertSame('[Ee][Nn]|[Dd][Ee]|[Ff][Rr]', $with->wheres['locale'] ?? null);
    }
}
