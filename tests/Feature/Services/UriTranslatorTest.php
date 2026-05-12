<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Tests\Feature\Services;

use Illuminate\Support\Facades\Lang;
use NielsNumbers\LaravelLocalizer\Services\UriTranslator;
use Orchestra\Testbench\TestCase;

class UriTranslatorTest extends TestCase
{
    protected UriTranslator $translator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->translator = new UriTranslator;

        Lang::addLines([
            'routes.about' => 'ueber',
            'routes.blog/post/{slug}' => 'artikel/{slug}',
            'routes.services/{type?}' => 'sluzby/{type?}',
            'routes.shop/{category?}/{slug?}' => 'laden/{category?}/{slug?}',
        ], 'de');
    }

    public function test_translates_full_uri()
    {
        $this->assertSame('ueber', $this->translator->translate('about', 'de'));
    }

    public function test_translates_full_uri_with_placeholder()
    {
        $this->assertSame(
            'artikel/{slug}',
            $this->translator->translate('blog/post/{slug}', 'de')
        );
    }

    public function test_returns_original_uri_when_no_translation_exists()
    {
        $this->assertSame('contact', $this->translator->translate('contact', 'de'));
    }

    public function test_does_not_translate_individual_segments()
    {
        // `routes.about` exists, but `about` as a segment inside another path
        // must not bleed into unrelated URIs. The whole URI is the lookup key.
        $this->assertSame(
            'blog/about/team',
            $this->translator->translate('blog/about/team', 'de')
        );
    }

    public function test_translates_uri_with_optional_placeholder()
    {
        // mcamara/laravel-localization#933: optional `{type?}` segments broke
        // because the upstream translator routed the path through parse_url(),
        // which treats `?` as the start of the query string and chops the
        // placeholder. Direct Lang lookup avoids that entirely.
        $this->assertSame(
            'sluzby/{type?}',
            $this->translator->translate('services/{type?}', 'de')
        );
    }

    public function test_translates_uri_with_multiple_optional_placeholders()
    {
        $this->assertSame(
            'laden/{category?}/{slug?}',
            $this->translator->translate('shop/{category?}/{slug?}', 'de')
        );
    }
}
