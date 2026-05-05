<?php

declare(strict_types=1);

namespace Piwigo\Job;

final readonly class GenerateDerivativeJob
{
    public function __construct(
        public int $imageId,
        public string $size,
    ) {
    }
}
