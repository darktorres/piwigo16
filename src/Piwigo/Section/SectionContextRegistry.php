<?php

declare(strict_types=1);

namespace Piwigo\Section;

use LogicException;
use Piwigo\Core\Kernel;

/**
 * Per-request holder for the current SectionContext.
 *
 * Singleton/service-locator elimination campaign, Phase 2: converted from
 * a self-managed static facade to a container-shared instance. Most real
 * readers/the writer (`SectionPopulator`) take it via constructor
 * injection; `Piwigo\Url\UrlService` keeps calling the `currentStatic()`
 * shim below -- it's one of the ~440-site manually-`new`'d classes Phase 6
 * exists to fix (see that phase's own scope), out of reach for real
 * constructor injection until then.
 */
final class SectionContextRegistry
{
    private ?SectionContext $current = null;

    public function set(SectionContext $context): void
    {
        $this->current = $context;
    }

    public function current(): ?SectionContext
    {
        return $this->current;
    }

    public function reset(): void
    {
        $this->current = null;
    }

    /**
     * @deprecated transitional bridge for `Piwigo\Url\UrlService`, one of
     * Phase 6's ~440 manually-`new`'d call sites -- PHP forbids an
     * instance method and a static method sharing one name, hence the
     * `Static` suffix. Delete once
     * `grep -rn "SectionContextRegistry::currentStatic("` outside tests/
     * returns nothing.
     *
     * Falls back to null (the same default `current()` reports before
     * anything has called `set()`) when `Kernel::boot()` hasn't run --
     * `current()` is already nullable everywhere it's read, so this
     * default is behavior-preserving, not a special case.
     */
    public static function currentStatic(): ?SectionContext
    {
        if (! Kernel::isBooted()) {
            return null;
        }

        $instance = Kernel::container()->get(self::class);
        if (! $instance instanceof self) {
            throw new LogicException('Container returned an unexpected type for ' . self::class);
        }

        return $instance->current();
    }
}
