<?php

declare(strict_types=1);

namespace Piwigo\Admin\Sync;

/** A single filesystem-sync info message: the affected path and a translated label. */
final readonly class SyncInfo
{
    public function __construct(
        public string $path,
        public string $info,
    ) {
    }
}
