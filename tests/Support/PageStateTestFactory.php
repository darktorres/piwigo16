<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;

/**
 * Returns the container-shared instance once Kernel has booted. Before
 * boot, returns a memoized fallback instance: PageState accumulates
 * state written by one caller (e.g. addError()) and read by another
 * later in the same request, so a fresh instance per call would
 * silently lose every write between calls.
 */
final class PageStateTestFactory
{
    private static ?PageState $fallback = null;

    public static function get(): PageState
    {
        if (Kernel::isBooted()) {
            $instance = Kernel::container()->get(PageState::class);
            if (! $instance instanceof PageState) {
                throw new LogicException('Container returned an unexpected type for ' . PageState::class);
            }

            return $instance;
        }

        return self::$fallback ??= new PageState();
    }
}
