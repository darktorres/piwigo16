<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Admin;

/**
 * A POP gadget whose `__wakeup()` records that it ran -- the payload
 * `UnserializeAllowedClassesTest` serializes to prove `allowed_classes: false`
 * really does defeat PHP Object Injection rather than merely being present in
 * the source.
 */
final class UnserializeAllowedClassesTestGadget
{
    public bool $woke = false;

    public function __wakeup(): void
    {
        $this->woke = true;
    }
}
