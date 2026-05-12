<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Services;

use Illuminate\Support\Facades\Lang;

/**
 * Translate route URIs using language files in lang/{locale}/routes.php.
 *
 * Only full-URI keys are honoured — define `routes.blog/post/{slug}`, not
 * `routes.blog`. Per-segment translation was removed because it produced
 * unintended hits when the same segment appeared in different contexts
 * (e.g. translating "about" everywhere, including `/blog/about/team`).
 */
class UriTranslator
{
    public function translate(string $uri, ?string $locale = null): string
    {
        $key = "routes.$uri";

        return Lang::has($key, $locale)
            ? Lang::get($key, [], $locale)
            : $uri;
    }
}
