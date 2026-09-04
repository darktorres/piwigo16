<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Uploads;

/**
 * `POST /api/v1/uploads/actions/complete-batch` body DTO -- carries only
 * `categoryId` (confirmed against the real caller, `photosAddDirect.ts`).
 * An `imageId` field is deliberately not carried: it would have zero real
 * reader, no production listener consumes it.
 */
final readonly class UploadCompleteBatchInput
{
    public function __construct(
        public int $categoryId,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $categoryId = $raw['categoryId'] ?? null;

        return new self(
            categoryId: is_int($categoryId) ? $categoryId : 0,
        );
    }
}
