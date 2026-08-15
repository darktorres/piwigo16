<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Category;

use Override;
use Piwigo\Core\ActivityLoggerInterface;

/**
 * Real fake for the 4 write methods' per-call ActivityLoggerInterface
 * parameter -- same shape as the Integration original's own local class,
 * just declared once here for the whole Unit file.
 */
final class CategoryServiceUnitTestFakeActivityLogger implements ActivityLoggerInterface
{
    /**
     * @var list<array{object: string, objectId: int|string|array<int, int|string>, action: string, details: array<string, mixed>}>
     */
    public array $calls = [];

    #[Override]
    public function record(string $object, int|string|array $objectId, string $action, array $details = []): void
    {
        $this->calls[] = [
            'object' => $object,
            'objectId' => $objectId,
            'action' => $action,
            'details' => $details,
        ];
    }
}
