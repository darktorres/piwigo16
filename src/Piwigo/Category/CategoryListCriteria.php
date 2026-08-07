<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Common\ValueObject\CategoryId;

/**
 * Further SQL-modernization audit, Item 13: replaces the `list<string>
 * $where` `Ws\PwgCategories::getList()` used to build -- exactly one of 3
 * mutually-exclusive scope conditions (recursive/non-recursive, with/
 * without a $catId), plus a permission-sourced forbidden-categories
 * exclusion (identically shaped whichever of the 3 branches -- public/
 * isAdmin()/else -- computed it, only the *value* differs by branch), plus,
 * in the public branch only, an extra `status = 'public' AND visible = 1`
 * pair. `CategoryRepository::findListForWs()` decides internally which of
 * these to add, from these plain fields, instead of the caller handing
 * over pre-built SQL text.
 */
final readonly class CategoryListCriteria
{
    /**
     * @param  list<int>  $forbiddenCategoryIds
     */
    public function __construct(
        public ?CategoryId $catId,
        public bool $recursive,
        public array $forbiddenCategoryIds,
        public bool $publicOnly,
    ) {}
}
