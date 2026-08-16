<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Piwigo\Permission\SqlCondition;

/**
 * SearchFilterRenderer::getClauseForFilter()'s own return shape -- pairs
 * the filter condition with an explicit "is this the generic,
 * permissions-only forbidden-images fallback, safe to cache across
 * requests" discriminant. Replaces every caller's own
 * `str_starts_with($condition->sql, 'ic.image IN')` string-content sniff,
 * which depended on SqlCondition::$sql always being real, inspectable SQL
 * text -- a dependency the §18 SqlCondition->Expr conversion would remove.
 * Carrying the boolean explicitly, set once at construction by the one
 * method that actually knows which shape it built, keeps every caller's
 * cache-applicability decision independent of whatever type $sql ends up
 * being.
 */
final readonly class SearchFilterClause
{
    public function __construct(
        public SqlCondition $condition,
        public bool $cacheApplicable,
    ) {}
}
