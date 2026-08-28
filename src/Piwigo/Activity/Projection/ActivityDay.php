<?php

declare(strict_types=1);

namespace Piwigo\Activity\Projection;

/**
 * One day cell of the dashboard's recent-activity heatmap, keyed
 * `[$week][$dayOfWeek]` by
 * {@see \Piwigo\Controller\Admin\IntroSubController::render()}.
 *
 * `$details` is the per-day breakdown the tooltip iterates twice, object
 * category to action name to count -- `['Album' => ['Add' => 3]]`. Both
 * levels are `ucfirst()`ed by the producer, which is what lets
 * `intro.latte` compare them against 'Album'/'Add' and pick an icon; the
 * outer level is `ksort()`ed so the categories render in a stable order.
 *
 * Frozen into this shape *after* the `$_SESSION['cache_activity_last_weeks']`
 * read, never before: that cache holds plain arrays on purpose, because a
 * serialized object embeds its class name and would not survive the class
 * moving namespace (docs/PLAN.md's P6).
 */
final readonly class ActivityDay
{
    /**
     * @param array<string, array<string, int>> $details
     */
    public function __construct(
        public int $number,
        public string $date,
        public array $details,
    ) {}
}
