<?php

declare(strict_types=1);

namespace Piwigo\Job;

/**
 * Represents a file already staged on disk (e.g. by a tus resumable
 * upload) that still needs to be committed to the gallery -- the
 * heavy part of the original synchronous UploadService::addUploadedFile()
 * (thumbnail generation, DB insert, category assignment, metadata sync).
 * Mirrors that method's own parameter shape; BatchUploadHandler is a
 * thin delegate to it.
 */
final readonly class BatchUploadJob
{
    /**
     * @param ?list<int> $categories
     */
    public function __construct(
        public string $sourceFilepath,
        public ?string $originalFilename = null,
        public ?array $categories = null,
        public ?int $level = null,
        public ?int $imageId = null,
        public ?string $originalMd5sum = null,
    ) {}
}
