<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * `user_activity.latte`'s `$activity_dates` min/max range, built by
 * {@see \Piwigo\Admin\UserActivityPageRenderer::render()} from
 * {@see \Piwigo\Activity\ActivityService::getMinOccuredOn()}/
 * `getMaxOccuredOn()`.
 */
final readonly class ActivityDateRange
{
    public function __construct(
        public string $min,
        public string $max,
    ) {}

    /**
     * @return array{min: string, max: string}
     */
    public function toArray(): array
    {
        return [
            'min' => $this->min,
            'max' => $this->max,
        ];
    }
}
