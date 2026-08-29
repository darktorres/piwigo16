<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance\Projection;

/**
 * One rendered row of the maintenance page's "sys" tab activity log, built
 * by {@see \Piwigo\Admin\Maintenance\ActivityLogEntryFormatter::format()}
 * from a {@see \Piwigo\Activity\Projection\SystemActivityLogEntry} and
 * consumed by `maintenance_sys.latte`.
 *
 * `$date` is the raw `Y-m-d` half of the row's `occured_on`, not a rendered
 * one: the template runs it through the `format_date` filter, which
 * delegates to the same `DateHelper::formatDate()` this used to call here.
 * `$hour` is the `H:i:s` half, rendered as-is.
 *
 * `$userId` is 0 for a row the query attributes to the system rather than to
 * a person. `findSystemObjectLogWithUsernames()` decides that with
 * `CASE WHEN a.performedByUser = 0 OR a.performedByUser IS NULL THEN
 * 'System'`, so a 0 here always travels with `$username === 'System'`, and
 * the template's user-colour branch -- the only reader of `$userId` -- sits
 * in the other arm of exactly that test.
 */
final readonly class ActivityLogRow
{
    /**
     * @param list<ActivityLogDetail> $detailItems zero, one or two chips.
     *   `$detailArrow` is true only for the two-item `from_version` ->
     *   `to_version` case, which renders an arrow between them.
     */
    public function __construct(
        public int $id,
        public bool $majorInfos,
        public string $objectIcon,
        public string $object,
        public string $actionIcon,
        public string $actionColor,
        public string $action,
        public int $userId,
        public ?string $username,
        public string $initial,
        public string $date,
        public string $hour,
        public array $detailItems,
        public bool $detailArrow,
    ) {}
}
