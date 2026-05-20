<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

/**
 * Verbs accepted by `Plugins::performAction()`,
 * `Themes::performAction()` and `Languages::performAction()` — plus the
 * WS extensions-perform-action handlers and the auto-update flow.
 *
 * The backing value is what arrives on the wire (`?action=…`) and what
 * the extension classes' internal `switch` arms key on. Languages and
 * themes accept a subset (Activate/Deactivate/Delete/SetDefault);
 * plugins additionally handle Install/Update/Uninstall/Restore. The
 * enum is the union — each registry rejects unsupported cases via its
 * own match.
 */
enum ExtensionAction: string
{
    case Install    = 'install';
    case Update     = 'update';
    case Activate   = 'activate';
    case Deactivate = 'deactivate';
    case Uninstall  = 'uninstall';
    case Restore    = 'restore';
    case Delete     = 'delete';
    case SetDefault = 'set_default';
}
