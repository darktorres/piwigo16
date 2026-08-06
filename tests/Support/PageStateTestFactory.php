<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;

/**
 * Singleton/service-locator elimination campaign, Phase 12 sub-phase 12F-7:
 * replaces the deleted `PageState::current()` transitional shim for test
 * call sites -- reproduces its exact behavior (the real container-shared
 * instance once Kernel has booted, a MEMOIZED, not fresh-per-call,
 * instance otherwise). PageState accumulates state written by one caller
 * (e.g. addError()) and read by another later in the same request -- a
 * fresh instance per call would silently lose every write between calls.
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
