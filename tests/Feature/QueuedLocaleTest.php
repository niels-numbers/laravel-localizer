<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Tests\Feature;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Traits\Localizable;
use NielsNumbers\LaravelLocalizer\ServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Documents how locale flows (or doesn't) through the four common non-HTTP
 * URL-generation contexts:
 *
 *   1. Plain queued job — Laravel does NOT propagate App::getLocale().
 *      The job runs in the worker's default locale unless it scopes one
 *      itself.  See laravel/ideas#394, closed without a fix.
 *   2. Job that scopes the recipient's locale via app()->withLocale().
 *   3. Mailable sent without ->locale() — uses the current app locale.
 *   4. Mailable sent with ->locale() — Laravel's PendingMail::locale wraps
 *      the build/send in withLocale (laravel/framework#23178).
 */
class QueuedLocaleTest extends TestCase
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

        Config::set('database.default', 'testbench');
        Config::set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        Config::set('queue.default', 'database');
        Config::set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'testbench',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);

        Config::set('cache.default', 'array');
        Config::set('mail.default', 'array');
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadLaravelMigrations();

        // Testbench's `loadLaravelMigrations()` only runs the top-level
        // migration directory; the `jobs` table migration lives in a
        // `queue/` subfolder on testbench v9+ and is missing entirely on
        // testbench v7/v8 (Laravel 9/10). Create it explicitly to keep
        // queue tests cross-version.
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
    }

    protected function defineRoutes($router)
    {
        Route::localize(function () {
            Route::get('/about', fn () => 'ok')->name('about');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('job_url');
        AboutLinkMail::$capturedUrl = null;
    }

    public function test_plain_queued_job_loses_dispatch_time_locale()
    {
        // Dispatched from a German request context.
        app()->setLocale('de');
        WriteAboutUrlJob::dispatch();

        // The worker process starts in the application's default locale.
        // Laravel does NOT carry the dispatch-time locale across this boundary
        // for plain jobs (only for Mailables and Notifications).
        app()->setLocale('en');

        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        $this->assertSame(
            '/about',
            Cache::get('job_url'),
            'A plain queued job runs in the worker default locale, not the dispatch-time locale.'
        );
    }

    public function test_job_can_scope_recipient_locale_with_with_locale()
    {
        // The job carries the recipient's locale itself and applies it
        // locally — no global App::setLocale() side effect that outlives
        // the URL build.
        app()->setLocale('en');
        WriteAboutUrlForLocaleJob::dispatch('de');

        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        $this->assertSame('/de/about', Cache::get('job_url'));
        $this->assertSame('en', app()->getLocale(), 'withLocale must restore the previous locale.');
    }

    public function test_mailable_without_locale_uses_current_app_locale()
    {
        // The mail is built and sent within whatever locale is currently
        // active. Predictable but only correct if the dispatching context
        // already set the recipient's locale.
        app()->setLocale('de');

        Mail::to('test@example.com')->send(new AboutLinkMail);

        $this->assertSame('/de/about', AboutLinkMail::$capturedUrl);
    }

    public function test_mailable_with_locale_renders_localized_urls()
    {
        // PendingMail::locale() instructs Laravel to wrap the entire send
        // (build + transport) in app()->withLocale($locale, ...). The current
        // app locale therefore reflects the recipient during render, even if
        // the global locale was different beforehand.
        app()->setLocale('en');

        Mail::to('test@example.com')->locale('de')->send(new AboutLinkMail);

        $this->assertSame('/de/about', AboutLinkMail::$capturedUrl);
        $this->assertSame('en', app()->getLocale(), 'withLocale must restore the previous locale.');
    }
}

class WriteAboutUrlJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        Cache::put('job_url', route('about', [], false));
    }
}

class WriteAboutUrlForLocaleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Localizable;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $recipientLocale) {}

    public function handle(): void
    {
        $this->withLocale($this->recipientLocale, function () {
            Cache::put('job_url', route('about', [], false));
        });
    }
}

class AboutLinkMail extends Mailable
{
    public static ?string $capturedUrl = null;

    public function build()
    {
        self::$capturedUrl = route('about', [], false);

        return $this->subject('test')->html('<p>about</p>');
    }
}
