<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Users;

/**
 * `POST /api/v1/users/{id}/actions/delete-ratings` body DTO -- mirrors
 * `Ws\Rates\DeleteParams`'s own optional `anonymousId`/`imageId` fields
 * (`userId` itself comes from the route).
 */
final readonly class UserDeleteRatingsInput
{
    public function __construct(
        public ?string $anonymousId,
        public ?int $imageId,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $anonymousId = $raw['anonymousId'] ?? null;
        $imageId = $raw['imageId'] ?? null;

        return new self(
            anonymousId: is_string($anonymousId) && $anonymousId !== '' ? $anonymousId : null,
            imageId: is_int($imageId) && $imageId !== 0 ? $imageId : null,
        );
    }
}
