<?php

declare(strict_types=1);

namespace NielsNumbers\LaravelLocalizer\Routing;

use NielsNumbers\LaravelLocalizer\Routing\Concerns\RewritesRoutesForLocale;
use Tightenco\Ziggy\Ziggy;

class LocalizerZiggyV1 extends Ziggy
{
    use RewritesRoutesForLocale;

    public function toArray(): array
    {
        $data = parent::toArray();
        $data['routes'] = $this->rewriteForCurrentLocale($data['routes']);

        return $data;
    }
}
