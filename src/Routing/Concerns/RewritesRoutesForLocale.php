<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Routing\Concerns;

use Illuminate\Support\Facades\App;
use NielsNumbers\LaravelLocalizer\Facades\Localizer;

trait RewritesRoutesForLocale
{
    /**
     * @param  array<string, mixed>  $routes
     * @return array<string, mixed>
     */
    protected function rewriteForCurrentLocale(array $routes): array
    {
        $appLocale = App::getLocale();
        $defaultLocale = Localizer::defaultLocale();
        $useUnprefixed = Localizer::hideDefaultLocale()
                      && $appLocale === $defaultLocale;
        $translatedPrefix = "translated_{$appLocale}.";

        $rewritten = [];

        foreach ($routes as $name => $def) {
            if (str_starts_with($name, 'with_locale.')) {
                if ($useUnprefixed) {
                    continue;
                }
                $rewritten[substr($name, 12)] = $def;

                continue;
            }
            if (str_starts_with($name, 'without_locale.')) {
                if (! $useUnprefixed) {
                    continue;
                }
                $rewritten[substr($name, 15)] = $def;

                continue;
            }
            if (str_starts_with($name, $translatedPrefix)) {
                $rewritten[substr($name, strlen($translatedPrefix))] = $def;

                continue;
            }
            if (str_starts_with($name, 'translated_')) {
                continue;
            }
            $rewritten[$name] = $def;
        }

        return $rewritten;
    }
}
