<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

use Piwigo\Permission\SqlCondition;

/**
 * Carries both a raw `FROM images [INNER JOIN image_category ON ...]`/
 * `WHERE ...` text-and-fragment pair and its DQL-property-path
 * equivalent, computed once by {@see CalendarService::buildInnerSql()}
 * (the underlying subcategory-id-resolution/`PermissionCriteria` calls
 * run once; only the final string assembly differs per target syntax).
 * `$rawSqlFrom` is plain text, not a `SqlCondition` -- it never carries a
 * bindable value (see `buildInnerSql()`'s own body: it is always either
 * `FROM images` or that plus a literal `INNER JOIN image_category ON id
 * = image_id`), unlike `$rawSqlWhere`, which does.
 * {@see CalendarRepository::findImageIds()} alone can still need the
 * raw-SQL shape: it takes an order fragment composed by
 * {@see CalendarRenderer::render()}, and a `` `rank` `` entry in it has no
 * `image_category` alias to resolve against in a calendar view. Every
 * other `CalendarRepository` method is real DQL and needs the DQL property
 * paths instead.
 */
final readonly class CalendarQueryScope
{
    public function __construct(
        public string $rawSqlFrom,
        public bool $joinImageCategory,
        public SqlCondition $rawSqlWhere,
        public SqlCondition $dqlWhere,
    ) {}
}
