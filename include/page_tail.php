<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\updates;
use Piwigo\Page\PageTailRenderer;

// Bootstrap globals, set by include/common.inc.php.
/** @var array<string, mixed> $conf */
global $conf;
/** @var float $t2 */
global $t2;

// --------------------------------------------------------- update notification
//
// Stays here rather than in PageTailRenderer: constructs Piwigo\Admin\
// updates, and L3Presentation (Page) may not depend on L4Integration
// (Admin) -- confirmed via a real deptrac violation when tried.
$update_notify_check_period = $conf['update_notify_check_period'];
if (is_int($update_notify_check_period) && $update_notify_check_period > 0) {
    $check_for_updates = false;

    $update_notify_last_check = $conf['update_notify_last_check'] ?? null;
    $update_notify_last_check = is_string($update_notify_last_check) ? $update_notify_last_check : null;

    if ($update_notify_last_check !== null) {
        if (strtotime($update_notify_last_check) < strtotime($update_notify_check_period . ' seconds ago')) {
            $check_for_updates = true;
        }
    } else {
        $check_for_updates = true;
    }

    if ($check_for_updates) {
        $exec_id = \Piwigo\Core\UniqueExecLock::begins('check_for_updates');
        if ($exec_id !== false) {
            include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
            $updates = new updates();
            $updates->notify_piwigo_new_versions();

            \Piwigo\Core\UniqueExecLock::ends('check_for_updates');
        }
    }
}

new PageTailRenderer()
    ->render($t2);
