<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// P23 sub-batch 8f-6: compatibility surface for a FROZEN one-shot
// historical script. The installation helpers that used to live here
// (execute_sqlfile/activate_core_themes/activate_core_plugins/
// install_db_connect) moved to Piwigo\Admin\Install\InstallService; every
// non-frozen caller targets that class directly. This file must keep
// existing at this exact path because the FROZEN install/db/86-database.php
// (excluded from migration by standing decision, still reachable through
// the real upgrade machinery) unconditionally
// `include_once PHPWG_ROOT_PATH . 'admin/include/functions_install.inc.php';`
// and then calls the bare activate_core_themes() -- same precedent as
// admin/include/functions.php's deliberately-kept empty stub and
// admin/include/functions_upgrade.php's frozen-script delegates. It is NOT
// a general-purpose API and disappears whenever the frozen install/db
// family does. function_exists() guard for safety against double-include
// on mixed entry paths, mirroring the other frozen-compat delegates.

if (! function_exists('activate_core_themes')) {
    /**
     * FROZEN-SCRIPT DELEGATE (single caller: install/db/86-database.php).
     */
    function activate_core_themes(): void
    {
        \Piwigo\Admin\Install\InstallService::activateCoreThemes();
    }
}
