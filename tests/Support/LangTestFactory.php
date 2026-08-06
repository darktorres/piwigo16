<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;

/**
 * Singleton/service-locator elimination campaign, Phase 12 sub-phase 12F-8:
 * replaces the deleted `Lang::current()` transitional shim for test call
 * sites -- reproduces its exact behavior. No pre-boot fallback (unlike
 * most closed shims in this campaign): Lang has required real
 * collaborators with no safe fake to fall back to, so this throws
 * naturally (via `Kernel::container()`) if called before `Kernel::boot()`,
 * matching the shim's own former shape exactly.
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
