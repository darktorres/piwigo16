<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Page;

use Piwigo\Core\TelemetrySenderInterface;

/**
 * TelemetrySenderInterface's own real implementation (PiwigoInfosSender)
 * needs 10 further heavy constructor deps for a config-gated send() that's
 * a no-op by default anyway -- a small, dedicated fake proves send() was
 * called without that cost.
 */
final class PageTailTestFakeTelemetrySender implements TelemetrySenderInterface
{
    public bool $sendWasCalled = false;

    public function send(): void
    {
        $this->sendWasCalled = true;
    }
}
