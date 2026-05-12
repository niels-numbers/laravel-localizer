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
 * End-to-end coverage for translated routes that contain {placeholder}
 * segments — e.g. /en/blog/post/{slug} and /de/blog/artikel/{slug}. The
 * translator must keep the placeholder intact while substituting the
 * surrounding literal segments, the router must resolve the per-locale
 * URI to the correct route, and route() must build the matching URL for
 * each locale from the same name.
 */
class TranslateMacroPlaceholderTest extends TestCase
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

        Lang::addLines(['routes.blog/post/{slug}' => 'blog/post/{slug}'], 'en');
        Lang::addLines(['routes.blog/post/{slug}' => 'blog/artikel/{slug}'], 'de');
    }

    private function defineTranslatedPostRoute(): void
    {
        Route::middleware(SetLocale::class)->group(function () {
            Route::translate(function () {
                Route::get(
                    Localizer::url('blog/post/{slug}'),
                    fn (string $slug) => App::getLocale().':'.$slug,
                )->name('post');
            });
        });
    }

    public function test_registers_per_locale_uris_with_placeholder_preserved()
    {
        $this->defineTranslatedPostRoute();
        Route::getRoutes()->refreshNameLookups();

        $this->assertSame(
            'en/blog/post/{slug}',
            Route::getRoutes()->getByName('translated_en.post')->uri()
        );
        $this->assertSame(
            'de/blog/artikel/{slug}',
            Route::getRoutes()->getByName('translated_de.post')->uri()
        );
        $this->assertSame(
            'blog/post/{slug}',
            Route::getRoutes()->getByName('without_locale.post')->uri()
        );
    }

    public function test_resolves_translated_url_with_placeholder_value()
    {
        $this->defineTranslatedPostRoute();

        $this->get('/de/blog/artikel/hallo-welt')
            ->assertOk()
            ->assertSeeText('de:hallo-welt');

        $this->get('/blog/post/hello-world')
            ->assertOk()
            ->assertSeeText('en:hello-world');
    }

    public function test_route_helper_builds_translated_url_per_locale()
    {
        $this->defineTranslatedPostRoute();
        Route::getRoutes()->refreshNameLookups();

        App::setLocale('de');
        $this->assertSame(
            url('/de/blog/artikel/hallo-welt'),
            route('post', ['slug' => 'hallo-welt'])
        );

        App::setLocale('en');
        $this->assertSame(
            url('/blog/post/hello-world'),
            route('post', ['slug' => 'hello-world'])
        );
    }

    public function test_optional_placeholder_resolves_with_and_without_value()
    {
        // mcamara/laravel-localization#933: optional `{type?}` segments
        // produced wrong/missing routes upstream because their translator
        // chopped the placeholder at `?`. Verify end-to-end here: routes
        // register, both `/de/sluzby/cloud` and `/de/sluzby` resolve, and
        // route() builds matching URLs in either form.
        Lang::addLines(['routes.services/{type?}' => 'services/{type?}'], 'en');
        Lang::addLines(['routes.services/{type?}' => 'sluzby/{type?}'], 'de');

        Route::middleware(SetLocale::class)->group(function () {
            Route::translate(function () {
                Route::get(
                    Localizer::url('services/{type?}'),
                    fn (?string $type = null) => App::getLocale().':'.($type ?? 'none'),
                )->name('service.detail');
            });
        });
        Route::getRoutes()->refreshNameLookups();

        $this->assertSame(
            'de/sluzby/{type?}',
            Route::getRoutes()->getByName('translated_de.service.detail')->uri()
        );

        $this->get('/de/sluzby/cloud')->assertOk()->assertSeeText('de:cloud');
        $this->get('/de/sluzby')->assertOk()->assertSeeText('de:none');
        $this->get('/services/cloud')->assertOk()->assertSeeText('en:cloud');
        $this->get('/services')->assertOk()->assertSeeText('en:none');

        App::setLocale('de');
        $this->assertSame(url('/de/sluzby/cloud'), route('service.detail', ['type' => 'cloud']));
        $this->assertSame(url('/de/sluzby'), route('service.detail'));

        App::setLocale('en');
        $this->assertSame(url('/services/cloud'), route('service.detail', ['type' => 'cloud']));
        $this->assertSame(url('/services'), route('service.detail'));
    }

    public function test_untranslated_locale_falls_back_to_original_uri_with_placeholder()
    {
        // German lang line is removed; the URI should fall through unchanged
        // (placeholder still preserved), so the German variant lives at the
        // English path under the /de prefix.
        Lang::addLines(['routes.blog/post/{slug}' => 'blog/post/{slug}'], 'de');

        $this->defineTranslatedPostRoute();

        $this->get('/de/blog/post/hallo-welt')
            ->assertOk()
            ->assertSeeText('de:hallo-welt');
    }
}
