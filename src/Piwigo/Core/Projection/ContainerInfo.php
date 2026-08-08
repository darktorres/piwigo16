<?php

declare(strict_types=1);

namespace Piwigo\Core\Projection;

/**
 * {@see \Piwigo\Core\ContainerDetector::detect()}'s own fixed 2-value
 * result, previously a bare `[string, ?string]` tuple destructured at
 * every one of its 4 real callers.
 */
final readonly class ContainerInfo
{
    public function __construct(
        public string $type,
        public ?string $version,
    ) {}
}
