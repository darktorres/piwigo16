<?php

declare(strict_types=1);

namespace Piwigo\Users\Event;

/**
 * Typed event covering both legacy `user_list_columns` (add a column to
 * the admin user list) and `after_render_user_list` (a final mutation
 * pass over the rendered list) filters. Both hooked the same real
 * capability -- customize/augment each row of the admin user list --
 * through the legacy DataTables server-side-processing shape (an
 * explicit `$aColumns` list plus a separate `$output['aaData']` render
 * pass); `GET /api/v1/users` has neither concept, just one self-describing
 * JSON row per user, so both collapse into a single filter over the
 * final row list, dispatched once, matching `Category\Event\
 * GetCategoriesMenuRows`'s own precedent for preserving a legacy
 * capability against a mechanism that no longer exists. Mutable on
 * `$rows`.
 *
 * @see \Piwigo\Controller\Api\Users\UserListController
 */
final class GetUserListRows
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        public array $rows,
    ) {}
}
