<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Services;

use Illuminate\Support\Facades\Config;
use Locale;

/**
 * Resolve the writing direction ('rtl' or 'ltr') for a locale.
 *
 * Resolution order, first match wins:
 *
 * 1. Explicit per-locale override in `localizer.locale_directions`,
 *    keyed by the locale code as it appears in `supported_locales`.
 *    Always wins, even over a contradicting script subtag.
 * 2. A script subtag carried in the locale itself (`uz-Arab`, `pa-Arab`)
 *    matched against the RTL script list. Requires `ext-intl`.
 * 3. The primary language subtag's default script, looked up against
 *    the language-to-script map, matched against the RTL script list.
 *
 * The defaults are the small set of scripts and languages that are
 * RTL in common modern use. Apps that need to extend either list can
 * merge into them via config (user values win on key conflicts).
 */
class LocaleDirection
{
    /**
     * ISO 15924 scripts written right-to-left.
     *
     * Limited to scripts in current use. Historic RTL scripts
     * (e.g. Phoenician, Old Hungarian) are deliberately omitted -
     * no Laravel app is shipping translations in them, and an
     * exhaustive list would only invite false positives.
     */
    public const DEFAULT_RTL_SCRIPTS = [
        'Arab', 'Hebr', 'Thaa', 'Nkoo', 'Mand', 'Adlm', 'Rohg', 'Yezi',
    ];

    /**
     * Default script for primary language subtags that do not carry
     * an explicit script subtag (e.g. `ar` instead of `ar-Arab`).
     *
     * Only RTL-by-default languages are listed - any language not in
     * the map (and not carrying a script subtag) is treated as Latin.
     */
    public const DEFAULT_LANGUAGE_SCRIPTS = [
        'ar' => 'Arab',
        'fa' => 'Arab',
        'ur' => 'Arab',
        'ps' => 'Arab',
        'sd' => 'Arab',
        'ks' => 'Arab',
        'ug' => 'Arab',
        'ku' => 'Arab',
        'he' => 'Hebr',
        'yi' => 'Hebr',
        'dv' => 'Thaa',
    ];

    public function for(string $locale): string
    {
        $overrides = Config::get('localizer.locale_directions', []);
        if (isset($overrides[$locale])) {
            return $overrides[$locale];
        }

        $userScripts = Config::get('localizer.rtl_scripts', []);
        $rtlScripts = array_values(array_unique([...self::DEFAULT_RTL_SCRIPTS, ...$userScripts]));

        return in_array($this->scriptFor($locale), $rtlScripts, true) ? 'rtl' : 'ltr';
    }

    public function current(): string
    {
        return $this->for(app()->getLocale());
    }

    /**
     * Pick a script for the locale. Prefers an explicit BCP 47 script
     * subtag (via ext-intl if available), falls back to the primary
     * language's default script.
     */
    protected function scriptFor(string $locale): string
    {
        if (extension_loaded('intl')) {
            $script = Locale::getScript($locale);
            if ($script !== '') {
                return $script;
            }
            $primary = Locale::getPrimaryLanguage($locale);
        } else {
            // BCP 47 separator is '-', but PHP and Laravel often use
            // '_' (de_DE). Treat both as subtag boundaries.
            $primary = strtolower((string) strtok($locale, '-_'));
        }

        $userMap = Config::get('localizer.language_scripts', []);
        $map = $userMap + self::DEFAULT_LANGUAGE_SCRIPTS;

        return $map[$primary] ?? 'Latn';
    }
}
