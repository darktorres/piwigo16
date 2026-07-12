<?php

declare(strict_types=1);

namespace Piwigo\Job;

/**
 * Mirrors Piwigo\Metadata\MetadataService::syncMetadata()'s own parameter
 * shape -- ReindexImagesHandler is a thin delegate to that real,
 * already-built service.
 */
final readonly class ReindexImagesJob
{
    /**
     * @param list<int> $imageIds
     */
    public function __construct(
        public array $imageIds,
    ) {}
}
