<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Routing;

use Tightenco\Ziggy\BladeRouteGenerator;
use Tightenco\Ziggy\Output\MergeScript;
use Tightenco\Ziggy\Output\Script;

class LocalizerBladeRouteGeneratorV1 extends BladeRouteGenerator
{
    public function generate($group = null, $nonce = null)
    {
        $ziggy = new LocalizerZiggyV1($group);

        $nonce = $nonce ? ' nonce="'.$nonce.'"' : '';

        if (static::$generated) {
            $output = config('ziggy.output.merge_script', MergeScript::class);

            return (string) new $output($ziggy, $nonce);
        }

        $function = config('ziggy.skip-route-function')
            ? ''
            : file_get_contents(base_path('vendor/tightenco/ziggy/dist/index.js'));

        static::$generated = true;

        $output = config('ziggy.output.script', Script::class);

        return (string) new $output($ziggy, $function, $nonce);
    }
}
