<?php

declare(strict_types=1);

namespace Piwigo\Admin\Sync;

/** A single filesystem-sync error: the affected path and a PWG error code. */
final readonly class SyncError
{
    public function __construct(
        public string $path,
        public string $type,
    ) {
    }
}
