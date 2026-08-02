<?php

declare(strict_types=1);

namespace Piwigo\Category;

/**
 * Further SQL-modernization audit, Item 13: replaces the `list<string>
 * $where` `Ws\PwgCategories::getAdminList()` used to build -- a `1=1`
 * base plus exactly one of 3 mutually-exclusive scope conditions
 * (non-recursive-with-cat_id / non-recursive-without / recursive), same
 * scope-condition shape as {@see CategoryListCriteria} but with no
 * permission filtering at all (this WS method is admin-only).
 */
final readonly class CategoryAdminListCriteria
{
    public function __construct(
        public ?int $catId,
        public bool $recursive,
    ) {}
}
