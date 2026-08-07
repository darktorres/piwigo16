<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Users\CurrentUser;

/**
 * Singleton/service-locator elimination campaign, Phase 12 sub-phase
 * 12F-11: replaces the deleted `CurrentUser::current()` transitional shim
 * for test call sites -- reproduces its exact behavior (the real
 * container-shared instance once Kernel has booted, a MEMOIZED, not
 * fresh-per-call, instance otherwise). A caller that writes via a call in
 * one test line and reads via a later call must see the same instance, or
 * the write would be lost.
 */
final class CurrentUserTestFactory
{
    private static ?CurrentUser $fallback = null;

    public static function get(): CurrentUser
    {
        if (Kernel::isBooted()) {
            $instance = Kernel::container()->get(CurrentUser::class);
            if (! $instance instanceof CurrentUser) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
            }

            return $instance;
        }

        return self::$fallback ??= new CurrentUser(new CurrentConfig());
    }
}
