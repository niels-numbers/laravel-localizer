<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use LogicException;
use NielsNumbers\LaravelLocalizer\Middleware\SetLocale;
use NielsNumbers\LaravelLocalizer\ServiceProvider;
use Orchestra\Testbench\TestCase;

final class LocalizedUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Log::swap(new \Illuminate\Log\Logger(
            new \Monolog\Logger('null', [new \Monolog\Handler\NullHandler()])
        ));
    }

    public function test_named_localize_route_returns_canonical_url_for_default_locale()
    {
        Route::middleware(SetLocale::class)->group(function () {
            Route::localize(function () {
                Route::get('/about', fn () => Route::localizedUrl('en', false))->name('about');
            });
        });

        // Visit the German variant. Switching to en (default + hidden) should
        // drop the prefix entirely — the canonical URL, not /en/about.
        $response = $this->get('/de/about');

        $response->assertOk();
        $response->assertSee('/about');
        $response->assertDontSee('/en/about');
    }

    public function test_named_localize_route_returns_prefixed_url_for_non_default_locale()
    {
        Route::middleware(SetLocale::class)->group(function () {
            Route::localize(function () {
                Route::get('/about', fn () => Route::localizedUrl('de', false))->name('about');
            });
        });

        // App is in en (default), unprefixed URL. Switching to de should add
        // the prefix.
        $response = $this->get('/about');

        $response->assertOk();
        $response->assertSee('/de/about');
    }

    public function test_named_translate_route_resolves_to_translated_uri()
    {
        Lang::addLines(['routes.about' => 'ueber'], 'de');
        Lang::addLines(['routes.about' => 'about'], 'en');

        Route::middleware(SetLocale::class)->group(function () {
            Route::translate(function () {
                Route::get(\NielsNumbers\LaravelLocalizer\Facades\Localizer::url('about'), function () {
                    return Route::localizedUrl('de', false);
                })->name('about');
            });
        });

        $response = $this->get('/about');

        $response->assertOk();
        $response->assertSee('/de/ueber');
    }

    public function test_unnamed_translated_route_throws()
    {
        Lang::addLines(['routes.about' => 'ueber'], 'de');
        Lang::addLines(['routes.about' => 'about'], 'en');

        Route::middleware(SetLocale::class)->group(function () {
            Route::translate(function () {
                Route::get(\NielsNumbers\LaravelLocalizer\Facades\Localizer::url('about'), function () {
                    return Route::localizedUrl('de', false);
                });
            });
        });

        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('unnamed translated route');

        $this->get('/about');
    }

    public function test_unnamed_localize_route_falls_back_to_uri_prefix_swap()
    {
        Route::middleware(SetLocale::class)->group(function () {
            Route::localize(function () {
                Route::get('/about', fn () => Route::localizedUrl('de', false));
            });
        });

        $response = $this->get('/about');

        $response->assertOk();
        $response->assertSee('/de/about');
    }

    public function test_unnamed_localize_route_swap_preserves_query_string()
    {
        Route::middleware(SetLocale::class)->group(function () {
            Route::localize(function () {
                Route::get('/about', fn () => Route::localizedUrl('de', false));
            });
        });

        $response = $this->get('/about?ref=newsletter');

        $response->assertOk();
        $response->assertSeeText('/de/about?ref=newsletter');
    }

    public function test_throws_when_called_outside_request_context()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('active matched route');

        Route::localizedUrl('de');
    }

    public function test_switcher_url_keeps_prefix_for_default_locale()
    {
        Route::middleware(SetLocale::class)->group(function () {
            Route::localize(function () {
                Route::get('/about', fn () => Route::localizedSwitcherUrl('en', false))->name('about');
            });
        });

        // Visiting /de/about and asking for the switcher URL to en (= default,
        // hidden) must keep the /en prefix so the next request carries the
        // locale signal to SetLocale.
        $response = $this->get('/de/about');

        $response->assertOk();
        $response->assertSee('/en/about');
    }

    public function test_switcher_url_for_non_default_locale_matches_localized_url()
    {
        Route::middleware(SetLocale::class)->group(function () {
            Route::localize(function () {
                Route::get('/about', fn () => Route::localizedSwitcherUrl('de', false))->name('about');
            });
        });

        // For non-default locales the URL is prefixed either way; switcher and
        // canonical are identical.
        $response = $this->get('/about');

        $response->assertOk();
        $response->assertSee('/de/about');
    }

    public function test_switcher_url_translate_route_uses_target_locale_uri()
    {
        Lang::addLines(['routes.about' => 'ueber'], 'de');
        Lang::addLines(['routes.about' => 'about'], 'en');

        Route::middleware(SetLocale::class)->group(function () {
            Route::translate(function () {
                Route::get(\NielsNumbers\LaravelLocalizer\Facades\Localizer::url('about'), function () {
                    return Route::localizedSwitcherUrl('en', false);
                })->name('about');
            });
        });

        // From /de/ueber, the switcher URL to en (= default, hidden) must keep
        // the /en prefix — same reason as the LocalizeMacro variant above.
        $response = $this->get('/de/ueber');

        $response->assertOk();
        $response->assertSee('/en/about');
    }

    public function test_switcher_url_unnamed_localize_route_keeps_prefix_for_default()
    {
        Route::middleware(SetLocale::class)->group(function () {
            Route::localize(function () {
                Route::get('/about', fn () => Route::localizedSwitcherUrl('en', false));
            });
        });

        $response = $this->get('/de/about');

        $response->assertOk();
        $response->assertSee('/en/about');
    }

    public function test_switcher_click_to_default_locale_overrides_stale_session()
    {
        // Real-world flow with both middlewares: a user browsing in DE has
        // 'de' in their session. Clicking the switcher hits /en/about, which
        // is the URL localizedSwitcherUrl('en') would emit from /de/about.
        // Without the prefix in the URL, SetLocale would read 'de' from the
        // session and RedirectLocale would bounce to /de/about — the bug
        // localizedSwitcherUrl exists to prevent.
        Config::set('localizer.persist_locale.session', true);

        Route::middleware([SetLocale::class, \NielsNumbers\LaravelLocalizer\Middleware\RedirectLocale::class])
            ->group(function () {
                Route::localize(function () {
                    Route::get('/about', fn () => app()->getLocale())->name('about');
                });
            });

        $response = $this->withSession(['locale' => 'de'])->get('/en/about');

        // /en/about → 302 to /about (RedirectLocale strips the default prefix);
        // the en value is now in the session for the follow-up.
        $response->assertRedirect('/about');
        $this->assertSame('en', session('locale'));
    }

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
}
