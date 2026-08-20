<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Core\Kernel;
use Piwigo\Core\LayoutState;

/**
 * Same "container-shared once booted, memoized fallback before" shape
 * as PageStateTestFactory -- see that class's own docblock.
 */
final class LayoutStateTestFactory
{
    private static ?LayoutState $fallback = null;

    public static function get(): LayoutState
    {
        if (Kernel::isBooted()) {
            $instance = Kernel::container()->get(LayoutState::class);
            if (! $instance instanceof LayoutState) {
                throw new LogicException('Container returned an unexpected type for ' . LayoutState::class);
            }

            return $instance;
        }

        return self::$fallback ??= new LayoutState();
    }
}
