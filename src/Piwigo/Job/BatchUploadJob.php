<?php

declare(strict_types=1);

namespace Piwigo\Job;

final readonly class BatchUploadJob
{
    public function __construct(
        public int $batchId,
    ) {
    }
}
