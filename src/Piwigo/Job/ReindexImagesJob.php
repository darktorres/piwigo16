<?php

declare(strict_types=1);

namespace Piwigo\Job;

final readonly class ReindexImagesJob
{
    public function __construct(
        public ?int $sinceId = null,
    ) {
    }
}
