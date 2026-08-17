<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Common\ValueObject\CategoryId;

/**
 * A `1=1` base plus exactly one of 3 mutually-exclusive scope conditions
 * (non-recursive-with-cat_id / non-recursive-without / recursive), same
 * scope-condition shape as {@see CategoryListCriteria} but with no
 * permission filtering at all -- this criteria is for the admin-only
 * category list.
 */
final readonly class CategoryAdminListCriteria
{
    public function __construct(
        public ?CategoryId $catId,
        public bool $recursive,
    ) {}
}
