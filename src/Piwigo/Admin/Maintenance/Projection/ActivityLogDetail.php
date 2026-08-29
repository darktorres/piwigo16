<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance\Projection;

/**
 * One detail chip of a system activity-log row, rendered by
 * `maintenance_sys.latte` as an icon plus its text.
 *
 * A row carries zero, one or two of these, decided by its own
 * `details` payload -- see {@see ActivityLogRow::$detailItems}, which is
 * where the arity and the arrow between a pair are documented.
 */
final readonly class ActivityLogDetail
{
    public function __construct(
        public string $icon,
        public string $text,
    ) {}
}
