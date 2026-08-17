<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Tags;

/**
 * `PATCH /api/v1/tags/{id}` body DTO -- mirrors `Ws\Tags\RenameParams`'s
 * own `new_name` field, fed from a decoded JSON body instead of WS's
 * `$params` array. `tagId`/`pwgToken` aren't part of this DTO -- the id
 * comes from the route, the CSRF token from the `X-CSRF-Token` header
 * (see `CsrfGuard`).
 */
final readonly class TagRenameInput
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
