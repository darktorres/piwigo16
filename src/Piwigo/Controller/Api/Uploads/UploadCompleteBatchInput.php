<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Uploads;

/**
 * `POST /api/v1/uploads/actions/complete-batch` body DTO -- mirrors
 * `Ws\Images\UploadCompletedParams`'s own `category_id` field.
 * `image_id` is deliberately not carried over: WS's own registrar
 * already documents it as wire-level-only back-compat for old clients,
 * with zero real reader since P32 Stage A5 deleted its only listener.
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
