<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Core\Kernel;
use Piwigo\Template\CurrentTemplate;

/**
 * Returns the container-shared CurrentTemplate instance once Kernel has
 * booted; falls back to a memoized (not fresh-per-call) instance
 * otherwise, mirroring CurrentTemplate's own former current() logic.
 */
final class CurrentTemplateTestFactory
{
    private static ?CurrentTemplate $fallback = null;

    public static function get(): CurrentTemplate
    {
        if (Kernel::isBooted()) {
            $instance = Kernel::container()->get(CurrentTemplate::class);
            if (! $instance instanceof CurrentTemplate) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentTemplate::class);
            }

            return $instance;
        }

        return self::$fallback ??= new CurrentTemplate();
    }
}
