<?php

declare(strict_types=1);

namespace Piwigo\Activity;

/**
 * Verbs persisted in the `activity.action` column. Closed set covering
 * every literal currently passed to {@see ActivityEvent::__construct}
 * plus the dynamic auth-flow reasons emitted by `AuthService`.
 *
 * Renaming a case is a schema-touching change — the value is what
 * ActivityLogger writes and what the admin "system activity" /
 * `pwg.activity.getList` UIs key on.
 */
enum ActivityAction: string
{
    // CRUD-shaped
    case Add        = 'add';
    case Edit       = 'edit';
    case Delete     = 'delete';
    case Move       = 'move';

    // Extension lifecycle (overlaps with ExtensionAction but persists here
    // independently — the activity surface is broader than the WS verbs).
    case Install    = 'install';
    case Update     = 'update';
    case AutoUpdate = 'autoupdate';
    case Activate   = 'activate';
    case Deactivate = 'deactivate';
    case Uninstall  = 'uninstall';
    case Restore    = 'restore';
    case SetDefault = 'set_default';

    // System events
    case Config      = 'config';
    case Maintenance = 'maintenance';

    // Auth flow
    case Login                       = 'login';
    case Logout                      = 'logout';
    case LoginFailureWrongPassword   = 'login_failure_wrong_password';
    case LoginFailureBeforeLogUser   = 'login_failure_before_log_user';
    case LoginFailureLocked          = 'login_failure_locked';
    case ResetPasswordSuccess        = 'reset_password_success';
    case ResetPasswordFailureCode    = 'reset_password_failure_code';
    case ResetPasswordFailureTooMany = 'reset_password_failure_too_many';
}
