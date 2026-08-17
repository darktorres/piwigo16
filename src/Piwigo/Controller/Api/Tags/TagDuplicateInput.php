<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Tags;

/**
 * `POST /api/v1/tags/{id}/actions/duplicate` body DTO.
 */
final readonly class TagDuplicateInput
{
    public function __construct(
        public string $name,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $name = $raw['name'] ?? null;

        return new self(
            name: is_string($name) ? $name : '',
        );
    }
}
