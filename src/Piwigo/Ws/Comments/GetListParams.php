<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Comments;

use Piwigo\Ws\WsParams;

/**
 * `pwg.userComments.getList` input DTO. Every field is already
 * present/typed by the time this runs -- `Server::invoke()`'s generic
 * signature validation (the method's own `MethodDefinition` registration
 * in `WsDefaultMethods.php`) guarantees `status`/`search`/`f_min_date`/
 * `f_max_date` are always present (non-null default), `page`/`per_page`
 * are always present ints, and `author_id`/`image_id` are either absent
 * or a positive int (`WsParamType::ID`). Business-rule validation
 * (status/per_page allowlists, date parseability) stays in
 * `GetListHandler` -- it returns specific 401 `WsErrorResponse` codes
 * today, not a generic `WsParamException`.
 */
final readonly class GetListParams implements WsParams
{
    public function __construct(
        public string $status,
        public ?string $search,
        public ?int $authorId,
        public ?int $imageId,
        public ?string $fMinDate,
        public ?string $fMaxDate,
        public int $page,
        public int $perPage,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $status = $raw['status'] ?? null;
        $search = $raw['search'] ?? null;
        $authorId = $raw['author_id'] ?? null;
        $imageId = $raw['image_id'] ?? null;
        $fMinDate = $raw['f_min_date'] ?? null;
        $fMaxDate = $raw['f_max_date'] ?? null;
        $page = $raw['page'] ?? null;
        $perPage = $raw['per_page'] ?? null;

        return new self(
            status: is_string($status) ? $status : 'all',
            search: is_string($search) ? $search : null,
            authorId: is_int($authorId) && $authorId !== 0 ? $authorId : null,
            imageId: is_int($imageId) && $imageId !== 0 ? $imageId : null,
            fMinDate: is_string($fMinDate) ? $fMinDate : null,
            fMaxDate: is_string($fMaxDate) ? $fMaxDate : null,
            page: is_int($page) ? $page : 0,
            perPage: is_int($perPage) ? $perPage : 0,
        );
    }
}
