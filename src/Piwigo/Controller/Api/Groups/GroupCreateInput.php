<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Groups;

/**
 * `POST /api/v1/groups` body DTO -- mirrors `Ws\Groups\AddParams`'s own
 * `name`/`is_default` fields, fed from a decoded JSON body instead of
 * WS's `$params` array.
 */
final readonly class GroupCreateInput
{
    public function __construct(
        public string $name,
        public bool $isDefault,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $name = $raw['name'] ?? null;
        $isDefault = $raw['isDefault'] ?? null;

        return new self(
            name: is_string($name) ? $name : '',
            isDefault: is_bool($isDefault) ? $isDefault : false,
        );
    }
}
