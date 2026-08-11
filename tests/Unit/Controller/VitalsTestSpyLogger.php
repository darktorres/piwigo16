<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Controller;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Piwigo\Controller\VitalsController -- only 2 constructor deps, no
 * template rendering at all (a fire-and-forget log sink for the real
 * navigator.sendBeacon() RUM beacon build/vitals.ts posts). No dedicated
 * Integration/Browser spec of its own.
 */
final class VitalsTestSpyLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string|Stringable, context: array<mixed>}>
     */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }
}
