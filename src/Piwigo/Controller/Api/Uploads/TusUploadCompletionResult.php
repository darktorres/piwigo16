<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Uploads;

/**
 * {@see TusUploadCompletionService::complete()}'s own success shape --
 * deliberately minimal (a client fetches `GET /api/v1/images/{id}` for
 * the rest, see that method's own docblock).
 */
final readonly class TusUploadCompletionResult
{
    public function __construct(
        public int $imageId,
        public string $addStatus,
    ) {}

    /**
     * @return array{imageId: int, addStatus: string}
     */
    public function toArray(): array
    {
        return [
            'imageId' => $this->imageId,
            'addStatus' => $this->addStatus,
        ];
    }
}
