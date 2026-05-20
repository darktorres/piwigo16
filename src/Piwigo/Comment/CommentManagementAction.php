<?php

declare(strict_types=1);

namespace Piwigo\Comment;

/**
 * Operator action on an existing comment — drives
 * `PermissionService::canManageComment()` and the admin-email
 * notifier. Distinct from {@see CommentModerationAction}, which
 * is the outcome of a brand-new submission going through the
 * moderation gate.
 */
enum CommentManagementAction: string
{
    case Delete   = 'delete';
    case Edit     = 'edit';
    case Validate = 'validate';
}
