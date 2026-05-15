<?php

declare(strict_types=1);

namespace Piwigo\Event\User;

/**
 * Typed event for legacy `delete_group` (notify).
 *
 * Dispatched from: src/Piwigo/Admin/Users/UserAdminService.php
 */
final readonly class DeleteGroup
{
    /**
     * @param array<mixed> $groupids
     */
    public function __construct(
        public array $groupids,
    ) {
    }
}
