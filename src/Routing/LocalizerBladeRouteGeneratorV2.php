<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Routing;

use Tighten\Ziggy\BladeRouteGenerator;
use Tighten\Ziggy\Output\Json;
use Tighten\Ziggy\Output\MergeScript;
use Tighten\Ziggy\Output\Script;

/**
 * Drop-in replacement for {@see BladeRouteGenerator} that emits a
 * locale-aware route table via {@see LocalizerZiggyV2}.
 *
 * Bind it in your AppServiceProvider so the `@routes` Blade directive picks
 * it up:
 *
 *     $this->app->bind(
 *         \Tighten\Ziggy\BladeRouteGenerator::class,
 *         \NielsNumbers\LaravelLocalizer\Routing\LocalizerBladeRouteGeneratorV2::class,
 *     );
 *
 * The Ziggy upstream generator instantiates `new Ziggy(...)` directly in
 * {@see BladeRouteGenerator::generate()}, so binding the Ziggy class itself
 * in the container does not affect `@routes`. This subclass swaps in the
 * locale-aware Ziggy variant.
 */
class LocalizerBladeRouteGeneratorV2 extends BladeRouteGenerator
{
    public function generate(array|string|null $group = null, ?string $nonce = null, ?bool $json = false): string
    {
        $ziggy = new LocalizerZiggyV2($group);

        if ($json) {
            $output = config('ziggy.output.json', Json::class);

            return (string) new $output($ziggy);
        }

        $nonce = $nonce ? " nonce=\"{$nonce}\"" : '';

        if (static::$generated) {
            $output = config('ziggy.output.merge_script', MergeScript::class);

            return (string) new $output($ziggy, $nonce);
        }

        static::$generated = true;

        $output = config('ziggy.output.script', Script::class);

        $routeFunction = config('ziggy.skip-route-function')
            ? ''
            : file_get_contents(base_path('vendor/tightenco/ziggy/dist/route.umd.js'));

        return (string) new $output($ziggy, $routeFunction, $nonce);
    }
}
