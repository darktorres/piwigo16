<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * One entry of `user_activity.latte`'s `$actions` filter dropdown, built
 * by {@see \Piwigo\Admin\UserActivityPageRenderer::render()} from a real
 * {@see \Piwigo\Activity\Projection\ActionCount} plus one spliced
 * view-only field (`value`, the `object/action` filter-param token).
 */
final readonly class UserActivityActionRow
{
    public function __construct(
        public string $object,
        public string $action,
        public int $counter,
        public string $value,
    ) {}

    /**
     * @return array{object: string, action: string, counter: int, value: string}
     */
    public function toArray(): array
    {
        return [
            'object' => $this->object,
            'action' => $this->action,
            'counter' => $this->counter,
            'value' => $this->value,
        ];
    }
}
