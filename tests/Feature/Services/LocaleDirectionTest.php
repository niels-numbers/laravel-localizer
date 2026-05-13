<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Tests\Feature\Services;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use NielsNumbers\LaravelLocalizer\Facades\Localizer as LocalizerFacade;
use NielsNumbers\LaravelLocalizer\ServiceProvider;
use NielsNumbers\LaravelLocalizer\Services\LocaleDirection;
use Orchestra\Testbench\TestCase;

class LocaleDirectionTest extends TestCase
{
    protected LocaleDirection $direction;

    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->direction = new LocaleDirection;
    }

    public function test_returns_ltr_for_latin_script_languages()
    {
        $this->assertSame('ltr', $this->direction->for('en'));
        $this->assertSame('ltr', $this->direction->for('de'));
        $this->assertSame('ltr', $this->direction->for('fr'));
    }

    public function test_returns_rtl_for_default_rtl_languages()
    {
        $this->assertSame('rtl', $this->direction->for('ar'));
        $this->assertSame('rtl', $this->direction->for('fa'));
        $this->assertSame('rtl', $this->direction->for('he'));
        $this->assertSame('rtl', $this->direction->for('ur'));
        $this->assertSame('rtl', $this->direction->for('dv'));
    }

    public function test_handles_regional_locales()
    {
        $this->assertSame('rtl', $this->direction->for('ar_EG'));
        $this->assertSame('rtl', $this->direction->for('ar-SA'));
        $this->assertSame('ltr', $this->direction->for('en_US'));
        $this->assertSame('ltr', $this->direction->for('pt-BR'));
    }

    public function test_script_subtag_overrides_language_default()
    {
        if (! extension_loaded('intl')) {
            $this->markTestSkipped('Script subtag detection needs ext-intl.');
        }

        // Uzbek and Punjabi can be written in either Arabic or Latin
        // script. The subtag carries the choice; the language alone
        // does not commit to a direction.
        $this->assertSame('rtl', $this->direction->for('uz-Arab'));
        $this->assertSame('ltr', $this->direction->for('uz-Latn'));
        $this->assertSame('rtl', $this->direction->for('pa-Arab'));
        $this->assertSame('ltr', $this->direction->for('pa-Guru'));
    }

    public function test_current_uses_app_locale()
    {
        App::setLocale('ar');
        $this->assertSame('rtl', $this->direction->current());

        App::setLocale('de');
        $this->assertSame('ltr', $this->direction->current());
    }

    public function test_explicit_override_wins_over_script_detection()
    {
        Config::set('localizer.locale_directions', [
            'ar' => 'ltr',
            'klingon' => 'rtl',
        ]);

        $this->assertSame('ltr', $this->direction->for('ar'));
        $this->assertSame('rtl', $this->direction->for('klingon'));
    }

    public function test_language_scripts_config_extends_defaults()
    {
        // Meitei Mayek is RTL-adjacent (vertical historically, but for
        // Laravel apps not a concern). Use an invented mapping to prove
        // the config wires through.
        Config::set('localizer.language_scripts', [
            'xyz' => 'Arab',
        ]);

        $this->assertSame('rtl', $this->direction->for('xyz'));
        $this->assertSame('rtl', $this->direction->for('ar'));
        $this->assertSame('ltr', $this->direction->for('de'));
    }

    public function test_rtl_scripts_config_extends_defaults()
    {
        Config::set('localizer.rtl_scripts', ['Cyrl']);
        Config::set('localizer.language_scripts', ['xx' => 'Cyrl']);

        $this->assertSame('rtl', $this->direction->for('xx'));
        $this->assertSame('rtl', $this->direction->for('ar'));
    }

    public function test_unknown_locale_falls_back_to_ltr()
    {
        $this->assertSame('ltr', $this->direction->for('klingon'));
        $this->assertSame('ltr', $this->direction->for(''));
    }

    public function test_localizer_pass_through_methods()
    {
        App::setLocale('ar');

        $this->assertSame('rtl', LocalizerFacade::localeDirection('ar'));
        $this->assertSame('ltr', LocalizerFacade::localeDirection('en'));
        $this->assertSame('rtl', LocalizerFacade::currentLocaleDirection());
    }
}
