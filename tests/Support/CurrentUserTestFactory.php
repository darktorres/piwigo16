<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Users\CurrentUser;

/**
 * Returns the container-shared instance once Kernel has booted. Before
 * boot, returns a memoized fallback instance rather than a fresh one per
 * call, so a caller that writes in one test line and reads in a later
 * line sees the same instance.
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
