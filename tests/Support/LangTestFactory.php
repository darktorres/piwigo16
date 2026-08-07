<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;

/**
 * Returns the container-shared Lang instance. There is no pre-boot
 * fallback: Lang has required collaborators with no safe fake to fall
 * back to, so this throws naturally (via `Kernel::container()`) if
 * called before `Kernel::boot()`.
 */
final class LangTestFactory
{
    public static function get(): Lang
    {
        $instance = Kernel::container()->get(Lang::class);
        if (! $instance instanceof Lang) {
            throw new LogicException('Container returned an unexpected type for ' . Lang::class);
        }

        return $instance;
    }
}
