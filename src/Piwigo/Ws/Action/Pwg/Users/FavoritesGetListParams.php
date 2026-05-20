<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Ws\WsParams;

/** `pwg.users.favorites.getList` input DTO. */
final readonly class FavoritesGetListParams implements WsParams
{
    public function __construct(
        public int $perPage,
        public int $page,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        return new self(
            perPage: is_numeric($raw['per_page'] ?? null) ? (int) $raw['per_page'] : 0,
            page:    is_numeric($raw['page']     ?? null) ? (int) $raw['page'] : 0,
        );
    }
}
