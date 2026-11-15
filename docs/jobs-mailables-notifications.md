# Locale in Jobs, Mailables and Notifications

The `SetLocale` middleware only runs during HTTP requests. Anywhere
else (queued jobs, mailables, notifications, console commands), the
application's locale is whatever the worker process has set globally,
typically `app.locale` from `config/app.php`.

This affects **everything that reads `App::getLocale()`**, not just URLs:

- `route('about')`: picks the wrong locale variant
- `__('messages.welcome')` / `@lang(...)` / `trans_choice(...)`: wrong language
- Validation messages
- `Carbon` / date formatting (`$date->translatedFormat(...)`, locale-aware diffs)
- Number / currency formatting via `Number::currency()`

Scoping the locale once at the right boundary fixes all of these
together. Laravel handles this for you in two of the three common cases.

## Mailables: automatic via `Mail::to()->locale()`

Pass the recipient's locale to the pending mail. Build, render and send
all run with that locale, so any `route(...)`, `__('...')`, validation
or date formatting inside your mailable resolves correctly:

```php
Mail::to($user)
    ->locale($user->locale)
    ->send(new InvoiceMail($invoice));
```

## Notifications: automatic via the notifiable's preferred locale

If your notifiable model implements `HasLocalePreference`, each
notification delivery runs with that locale automatically:

```php
class User extends Model implements HasLocalePreference
{
    public function preferredLocale(): string
    {
        return $this->locale;
    }
}
```

## Plain queued jobs: manual

There is **no** built-in propagation for arbitrary queued jobs. You
have to scope the locale yourself; easiest by adding the `Localizable`
trait to your job and wrapping the locale-sensitive work in
`$this->withLocale(...)`. URLs, translations, validation, dates etc.
inside the closure all see the scoped locale:

```php
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Traits\Localizable;

class SendReminder implements ShouldQueue
{
    use Dispatchable, Queueable, Localizable;

    public function __construct(public User $user) {}

    public function handle(): void
    {
        $this->withLocale($this->user->locale, function () {
            $url     = route('dashboard');
            $subject = __('reminders.subject');
            // …send the reminder using $url and $subject
        });
    }
}
```

If your job's only job is to send a mail or notification, you don't
need this trait; `Mail::to()->locale()` and `HasLocalePreference`
already wrap the relevant code in `withLocale(...)` for you.
