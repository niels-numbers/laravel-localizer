<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use NielsNumbers\LaravelLocalizer\Services\LocaleDirection;
use NielsNumbers\LaravelLocalizer\Services\UriTranslator;

class Localizer
{
    /**
     * Runtime override for the active subset of supported locales.
     * `null` means: active === supported (no override).
     *
     * @var array<int, string>|null
     */
    protected ?array $activeLocales = null;

    /**
     * Runtime override for the default locale (the one whose URLs are
     * unprefixed when `hide_default_locale` is on). `null` means: fall
     * back to `config('app.fallback_locale')`.
     */
    protected ?string $activeDefaultLocale = null;

    public function __construct(
        protected UriTranslator $translator,
        protected LocaleDirection $direction,
    ) {}

    /**
     * @return array<int, string>
     */
    public function supportedLocales(): array
    {
        return Config::get('localizer.supported_locales', []);
    }

    public function isSupported(?string $locale): bool
    {
        if ($locale === null) {
            return false;
        }

        foreach ($this->supportedLocales() as $supported) {
            if (strcasecmp($supported, $locale) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the canonical form of $locale as configured in
     * `supported_locales`, matched case-insensitively. Returns the input
     * unchanged when no match exists, so callers passing unsupported
     * values (e.g. `'klingon'`) keep the existing pass-through behavior.
     *
     * Use this whenever the package consumes a locale value that may
     * originate from app code (App::setLocale('EN')), DB columns with
     * legacy uppercase storage, or third-party detectors. Centralizing
     * the lookup keeps the rest of the package free of strtolower()
     * sprinkles and works correctly for non-trivial codes (pt-BR, zh-Hant)
     * because the canonical form comes from config, not from a string op.
     */
    public function canonicalize(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }

        foreach ($this->supportedLocales() as $supported) {
            if (strcasecmp($supported, $locale) === 0) {
                return $supported;
            }
        }

        return $locale;
    }

    /**
     * Locales the user is allowed to reach in the current request.
     * Defaults to `supportedLocales()`; can be narrowed at runtime via
     * `setActiveLocales()` (e.g. per tenant in a multi-tenant app).
     *
     * @return array<int, string>
     */
    public function activeLocales(): array
    {
        return $this->activeLocales ?? $this->supportedLocales();
    }

    public function isActive(?string $locale): bool
    {
        if ($locale === null) {
            return false;
        }

        foreach ($this->activeLocales() as $active) {
            if (strcasecmp($active, $locale) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Narrow the active locales for the current request. Pass `null` to
     * reset to `supportedLocales()` — important in long-running workers
     * (Octane, queue) where the Localizer singleton survives the request.
     *
     * @param  array<int, string>|null  $locales
     */
    public function setActiveLocales(?array $locales): void
    {
        $this->activeLocales = $locales;
    }

    /**
     * The default locale for the current request. Defaults to
     * `config('app.fallback_locale')`; can be overridden at runtime via
     * `setActiveDefaultLocale()` (e.g. per tenant in a multi-tenant app).
     *
     * Drives the unprefixed URL variant when `hide_default_locale` is on,
     * the SetLocale fallback, and the RedirectLocale prefix-strip rule.
     */
    public function defaultLocale(): string
    {
        return $this->activeDefaultLocale ?? Config::get('app.fallback_locale');
    }

    /**
     * Override the default locale for the current request. Pass `null` to
     * reset to `config('app.fallback_locale')` — important in long-running
     * workers (Octane, queue) where the Localizer singleton survives the
     * request.
     *
     * Has no effect on routes that were already registered at boot time
     * (Route::translate() bakes the without-locale variant against the
     * boot-time default). Affects URL generation, RedirectLocale and
     * SetLocale at request time.
     */
    public function setActiveDefaultLocale(?string $locale): void
    {
        $this->activeDefaultLocale = $locale;
    }

    public function hideDefaultLocale(): bool
    {
        return Config::get('localizer.hide_default_locale', true);
    }

    public function storesInSession(): bool
    {
        return Config::get('localizer.persist_locale.session', true);
    }

    public function storesInCookie(): bool
    {
        return Config::get('localizer.persist_locale.cookie', true);
    }

    /**
     * @return array<int, class-string>
     */
    public function detectors(): array
    {
        return Config::get('localizer.detectors', []);
    }

    public function url(string $uri, ?string $locale = null): string
    {
        return $this->translator->translate($uri, $locale);
    }

    public function macroRegisterName(): string
    {
        return 'localize';
    }

    /**
     * Strip the localizer prefix from a route name and return the bare base
     * name. `with_locale.about` / `without_locale.about` /
     * `translated_de.about` all collapse to `about`. Names that carry no
     * localizer prefix (foreign-named routes like `admin.dashboard`) and
     * `null` are returned unchanged, so the helper is safe to chain after
     * `Route::current()?->getName()` without a null guard at the call site.
     */
    public function baseName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        foreach (['with_locale.', 'without_locale.'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return substr($name, strlen($prefix));
            }
        }

        if (str_starts_with($name, 'translated_')) {
            $dot = strpos($name, '.');

            // No dot after `translated_`: not a name our TranslateMacro would
            // produce (the macro always registers as `translated_{locale}.`).
            // Treat it as a foreign name and return unchanged.
            return $dot === false ? $name : substr($name, $dot + 1);
        }

        return $name;
    }

    /**
     * Writing direction ('rtl' or 'ltr') for the given locale. See
     * `LocaleDirection` for the resolution order and how to extend
     * the script/language defaults via config.
     */
    public function localeDirection(string $locale): string
    {
        return $this->direction->for($locale);
    }

    /**
     * Writing direction for the current application locale
     * (`app()->getLocale()`). Convenience for templates:
     * `<html dir="{{ Localizer::currentLocaleDirection() }}">`.
     */
    public function currentLocaleDirection(): string
    {
        return $this->direction->current();
    }
}
