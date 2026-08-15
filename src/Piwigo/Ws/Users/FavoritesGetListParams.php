<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Users;

use Piwigo\Ws\WsParams;

/**
 * `pwg.users.favorites.getList` input DTO -- the pagination fields only.
 * `order` stays in the raw `$params` array and is read directly by
 * `ImageSqlOrderBuilder::stdImageSqlOrder()`, same as the god-class method this
 * replaces -- no need to duplicate its shape here. `per_page`/`page`:
 * non-null default, always present.
 */
final readonly class FavoritesGetListParams implements WsParams
{
    public function __construct(
        public int $perPage,
        public int $page,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $perPage = $raw['per_page'] ?? null;
        $page = $raw['page'] ?? null;

        return new self(
            perPage: is_int($perPage) ? $perPage : 100,
            page: is_int($page) ? $page : 0,
        );
    }
}
