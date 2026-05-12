<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Tests\Integration\Livewire3;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use NielsNumbers\LaravelLocalizer\Middleware\RedirectLocale;
use NielsNumbers\LaravelLocalizer\Middleware\SetLocale;
use NielsNumbers\LaravelLocalizer\ServiceProvider;
use Orchestra\Testbench\TestCase;

class LocaleProbe extends Component
{
    public string $observed = '';

    public function detect(): void
    {
        $this->observed = app()->getLocale();
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>locale={{ $observed ?: 'none' }}</div>
        BLADE;
    }
}

class LivewireLocaleTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(Livewire::class)) {
            $this->markTestSkipped('livewire/livewire is not installed.');
        }

        // Livewire 4 introduces EndpointResolver (randomized update path) and
        // RequireLivewireHeaders middleware. The v3 suite assumes the v3
        // contract: /livewire/update with no header guard. Skip if v4 is in.
        if (class_exists(EndpointResolver::class)) {
            $this->markTestSkipped('livewire/livewire 4.x detected; covered by the Livewire4 suite.');
        }

        parent::setUp();

        Log::swap(new \Illuminate\Log\Logger(
            new Logger('null', [new NullHandler])
        ));
    }

    protected function getPackageProviders($app)
    {
        return [
            ServiceProvider::class,
            LivewireServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        Config::set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        Config::set('app.locale', 'en');
        Config::set('app.fallback_locale', 'en');
        Config::set('localizer.supported_locales', ['en', 'de']);
        Config::set('localizer.hide_default_locale', true);
        Config::set('localizer.persist_locale.session', false);
        Config::set('localizer.persist_locale.cookie', false);
    }

    protected function defineRoutes($router)
    {
        Livewire::component('locale-probe', LocaleProbe::class);

        view()->addNamespace('test', __DIR__.'/views');

        $router->middleware([SetLocale::class, RedirectLocale::class])->group(function () {
            Route::localize(function () {
                Route::get('/page', fn () => view('test::page'))->name('page');
            });

            // Plain unlocalized route inside the same middleware group. SetLocale
            // sees no `locale_type` action attribute and skips it — used by the
            // negative test below to demonstrate the caveat.
            Route::get('/admin', fn () => view('test::page'))->name('admin');
        });
    }

    public function test_initial_render_on_localized_page_embeds_locale_into_snapshot(): void
    {
        // SetLocale runs on /de/page and sets App::getLocale() = 'de'. Livewire's
        // SupportLocales hook copies that into memo.locale on the rendered
        // snapshot. This is what makes the package work without a recipe.
        $response = $this->get('/de/page');

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/wire:snapshot="[^"]*&quot;locale&quot;:&quot;de&quot;[^"]*"/',
            $response->getContent()
        );
    }

    public function test_livewire_update_post_without_locale_prefix_restores_locale_from_snapshot(): void
    {
        // Reproduce the exact flow the JS client performs:
        //   1. GET /de/page  → snapshot carries memo.locale = 'de'
        //   2. POST /livewire/update (no locale prefix!) with that snapshot
        //      and a method call.
        // The method must execute with App::getLocale() === 'de', proving
        // the package works out-of-the-box with Livewire 3 — no setUpdateRoute
        // recipe needed, because Livewire's SupportLocales hook restores the
        // locale from memo before the action runs.
        $response = $this->get('/de/page');
        $response->assertOk();

        preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $m);
        $snapshot = html_entity_decode($m[1], ENT_QUOTES);

        $update = $this->postJson('/livewire/update', [
            '_token' => csrf_token(),
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [[
                    'method' => 'detect',
                    'params' => [],
                ]],
            ]],
        ]);

        $update->assertOk();

        $newSnapshot = $update->json('components.0.snapshot');
        $this->assertNotNull($newSnapshot, 'expected updated snapshot in response');

        $data = json_decode($newSnapshot, true);
        $this->assertSame('de', $data['data']['observed']);
    }

    public function test_component_on_unlocalized_route_freezes_default_locale_into_memo(): void
    {
        // Negative case (caveat documented in docs/caveats-and-recipes.md):
        // when a component is first rendered on a plain route (not wrapped in
        // Route::localize()/translate()), SetLocale skips it. Even with a
        // de-DE browser, App::getLocale() at render time is the configured
        // app.locale ('en'). dehydrate() writes that into memo.locale, and
        // every subsequent update will hydrate to 'en' — regardless of what
        // the user's browser, session, or any other route says.
        $response = $this->get('/admin', ['Accept-Language' => 'de-DE,de;q=0.9']);
        $response->assertOk();

        preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $m);
        $snapshot = html_entity_decode($m[1], ENT_QUOTES);

        $this->assertStringContainsString('"locale":"en"', $snapshot);

        $update = $this->postJson('/livewire/update', [
            '_token' => csrf_token(),
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [[
                    'method' => 'detect',
                    'params' => [],
                ]],
            ]],
        ]);

        $update->assertOk();
        $data = json_decode($update->json('components.0.snapshot'), true);
        $this->assertSame('en', $data['data']['observed']);
    }
}
