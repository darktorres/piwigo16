<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Extensions;

/**
 * `POST /api/v1/extensions/updates/ignore` body DTO -- mirrors
 * `Ws\Extensions\IgnoreUpdateParams`'s own `type`/`id`/`reset` fields.
 */
final readonly class IgnoreUpdateInput
{
    public function __construct(
        public ?string $type,
        public ?string $id,
        public bool $reset,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $type = $raw['type'] ?? null;
        $id = $raw['id'] ?? null;
        $reset = $raw['reset'] ?? null;

        return new self(
            type: is_string($type) ? $type : null,
            id: is_string($id) ? $id : null,
            reset: is_bool($reset) ? $reset : false,
        );
    }
}
