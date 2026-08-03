<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

use Piwigo\Permission\SqlCondition;

/**
 * Replaces the old single opaque `?SqlCondition` {@see CalendarService::
 * buildInnerSql()} used to return -- a raw `FROM images [INNER JOIN
 * image_category ON ...] WHERE ...` text fragment consumed by every
 * {@see CalendarRepository} method alike. `CalendarRepository::
 * findImageIds()` alone stays on raw DBAL (its own `$orderBySql` traces
 * to `CurrentConfig::orderBy()`/`orderByCustom()`, genuinely open-ended
 * admin-typed raw SQL -- a real Item-16-scoped blocker, not a stale
 * "not worth it" claim), so it alone still needs the original raw-SQL
 * shape; every other `CalendarRepository` method is now real DQL and
 * needs DQL property paths instead. Both representations are computed
 * once by the same {@see CalendarService::buildInnerSql()} call (the
 * underlying subcategory-id-resolution/`PermissionCriteria` calls run
 * once; only the final string assembly differs per target syntax).
 */
final readonly class CalendarQueryScope
{
    public function __construct(
        public SqlCondition $rawSqlFromWhere,
        public bool $joinImageCategory,
        public SqlCondition $dqlWhere,
    ) {}
}
