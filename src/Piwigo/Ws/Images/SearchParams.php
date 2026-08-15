<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Piwigo\Ws\WsParams;

/**
 * `pwg.images.search` input DTO -- the method-specific fields only. The
 * shared `f_*` image-filter params (merged into this method's own
 * registration via ws.php's `$f_params`) stay in the raw `$params`
 * array and are read directly by
 * `ImageFilterCriteriaBuilder::stdImageSqlFilterCriteria()`/`stdImageSqlOrder()`, same as
 * the god-class method this replaces -- no need to duplicate their
 * shape here.
 *
 * `query`: no 'default' key -- mandatory, always a plain string.
 * `per_page`/`page`: non-null int defaults -- always present. `order`:
 * not a field here -- read directly off raw `$params` by
 * `ImageSqlOrderBuilder::stdImageSqlOrder()`, same as the `f_*` fields.
 */
final readonly class SearchParams implements WsParams
{
    public function __construct(
        public string $query,
        public int $perPage,
        public int $page,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $query = $raw['query'] ?? null;
        $perPage = $raw['per_page'] ?? null;
        $page = $raw['page'] ?? null;

        return new self(
            query: is_string($query) ? $query : '',
            perPage: is_int($perPage) ? $perPage : 100,
            page: is_int($page) ? $page : 0,
        );
    }
}
