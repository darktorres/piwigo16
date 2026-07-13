<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/batch_manager.php (page slug "batch_manager") -- a tab
 * dispatcher (global/unit) that stays a pure delegate; its own tab-dispatch
 * include shape is unchanged (already validated via check_input_parameter()
 * against /^(global|unit)$/, unlike admin/updates.php's tab -- see
 * UpdatesSubController's own docblock for that LFI fix). The file's real
 * "data access" concern (resolving $_SESSION['bulk_manager_filter'] into
 * photo id lists) was migrated off ~320 lines of inline SQL onto the new
 * Admin\BatchManager\FilterResolver (this batch's real scope); the 2 tab
 * files it dispatches to (batch_manager_global.php/batch_manager_unit.php)
 * and their shared admin/include/batch_manager_filters.inc.php remain
 * procedural glue, calling already-tested free functions
 * (add_tags()/associate_images_to_categories()/mass_updates()/etc.) same
 * as before.
 */
final class BatchManagerSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/batch_manager.php';
    }
}
