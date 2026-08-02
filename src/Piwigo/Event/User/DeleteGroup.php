<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for the legacy `delete_group` notification. No handler is
 * registered for it anywhere today.
 */
final readonly class DeleteGroup
{
    /**
     * @param list<int> $groupIds
     */
    public function __construct(
        public array $groupIds,
    ) {}
}
