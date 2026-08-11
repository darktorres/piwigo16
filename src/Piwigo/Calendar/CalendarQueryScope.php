<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

use Piwigo\Permission\SqlCondition;

/**
 * Carries both a raw `FROM images [INNER JOIN image_category ON ...]
 * WHERE ...` text fragment and its DQL-property-path equivalent, computed
 * once by {@see CalendarService::buildInnerSql()} (the underlying
 * subcategory-id-resolution/`PermissionCriteria` calls run once; only the
 * final string assembly differs per target syntax). `CalendarRepository::
 * findImageIds()` alone stays on raw DBAL and needs the raw-SQL shape --
 * its own `$orderBySql` traces to `CurrentConfig::orderBy()`/
 * `orderByCustom()`, genuinely open-ended admin-typed raw SQL that can't
 * be safely expressed as DQL; every other `CalendarRepository` method is
 * real DQL and needs the DQL property paths instead.
 */
final readonly class CalendarQueryScope
{
    public function __construct(
        public SqlCondition $rawSqlFromWhere,
        public bool $joinImageCategory,
        public SqlCondition $dqlWhere,
    ) {}
}
