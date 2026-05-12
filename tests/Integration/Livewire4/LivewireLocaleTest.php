<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Tests\Integration\Livewire4;

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

        // Livewire 4 randomizes the update endpoint to /livewire-{hash}/update.
        // The test reads the URI from the rendered HTML, so the runtime
        // version still has to be 4.x for this suite to be meaningful.
        if (! class_exists(EndpointResolver::class)) {
            $this->markTestSkipped('livewire/livewire 4.x is not installed.');
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
        // Fixed key so EndpointResolver yields the same /livewire-{hash}/update
        // path on every request inside a test (the prefix is derived from app.key).
        Config::set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
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

            Route::get('/admin', fn () => view('test::page'))->name('admin');
        });
    }

    /**
     * Pull the data-update-uri value off Livewire's injected script tag.
     * In v3 this is always /livewire/update; in v4 it's randomized per
     * app.key (see Livewire\Mechanisms\HandleRequests\EndpointResolver).
     */
    private function extractUpdateUri(string $html): string
    {
        if (! preg_match('/data-update-uri="([^"]+)"/', $html, $m)) {
            $this->fail('no data-update-uri in HTML; head of body: '.substr($html, 0, 800));
        }

        // Livewire 4 emits an absolute URL ("http://localhost/livewire-{hash}/update");
        // Laravel's testing client expects a path. Strip the host if present.
        return parse_url($m[1], PHP_URL_PATH) ?? $m[1];
    }

    private function extractSnapshot(string $html): string
    {
        preg_match('/wire:snapshot="([^"]+)"/', $html, $m);

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    public function test_initial_render_on_localized_page_embeds_locale_into_snapshot(): void
    {
        $response = $this->get('/de/page');

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/wire:snapshot="[^"]*&quot;locale&quot;:&quot;de&quot;[^"]*"/',
            $response->getContent()
        );
    }

    public function test_livewire_update_post_without_locale_prefix_restores_locale_from_snapshot(): void
    {
        $response = $this->get('/de/page');
        $response->assertOk();

        $updateUri = $this->extractUpdateUri($response->getContent());
        $snapshot = $this->extractSnapshot($response->getContent());

        $update = $this->postJson($updateUri, [
            '_token' => csrf_token(),
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [[
                    'method' => 'detect',
                    'params' => [],
                ]],
            ]],
        ], ['X-Livewire' => '1']);

        $update->assertOk();

        $newSnapshot = $update->json('components.0.snapshot');
        $this->assertNotNull($newSnapshot, 'expected updated snapshot in response');

        $data = json_decode($newSnapshot, true);
        $this->assertSame('de', $data['data']['observed']);
    }

    public function test_component_on_unlocalized_route_freezes_default_locale_into_memo(): void
    {
        $response = $this->get('/admin', ['Accept-Language' => 'de-DE,de;q=0.9']);
        $response->assertOk();

        $updateUri = $this->extractUpdateUri($response->getContent());
        $snapshot = $this->extractSnapshot($response->getContent());

        $this->assertStringContainsString('"locale":"en"', $snapshot);

        $update = $this->postJson($updateUri, [
            '_token' => csrf_token(),
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [[
                    'method' => 'detect',
                    'params' => [],
                ]],
            ]],
        ], ['X-Livewire' => '1']);

        $update->assertOk();
        $data = json_decode($update->json('components.0.snapshot'), true);
        $this->assertSame('en', $data['data']['observed']);
    }
}
