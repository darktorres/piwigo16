<?php

declare(strict_types=1);

namespace Piwigo\Job;

use Piwigo\Common\ValueObject\ImageId;

/**
 * Represents a file already staged on disk (e.g. by a tus resumable
 * upload) that still needs to be committed to the gallery -- the
 * heavy part of the original synchronous UploadService::addUploadedFile()
 * (thumbnail generation, DB insert, category assignment, metadata sync).
 * Mirrors that method's own parameter shape; BatchUploadHandler is a
 * thin delegate to it.
 *
 * $imageId is a real {@see ImageId}, not a raw int -- the
 * one real consumer ({@see \Piwigo\Job\Handler\BatchUploadHandler}) unwraps
 * `->value` at its own call into `UploadService::addUploadedFile()`, whose
 * own `?int $image_id` parameter stays untouched here (its other real
 * caller, {@see \Piwigo\Controller\Api\Uploads\TusUploadCompletionService},
 * is well outside this job-queue path, out of this DTO's scope).
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
        public ?ImageId $imageId = null,
        public ?string $originalMd5sum = null,
    ) {}
}
