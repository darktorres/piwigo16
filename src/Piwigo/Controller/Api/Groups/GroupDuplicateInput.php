<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Groups;

/**
 * `POST /api/v1/groups/{id}/actions/duplicate` body DTO.
 */
final readonly class GroupDuplicateInput
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
