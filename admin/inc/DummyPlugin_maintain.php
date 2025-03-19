<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin\inc;

use Piwigo\inc\PluginMaintain;

/**
 * used when a plugin uses the old procedural declaration of maintenance methods
 */
class DummyPlugin_maintain extends PluginMaintain
{
    public function install(
        string $plugin_version,
        array &$errors = []
    ): void {
        if (is_callable('plugin_install')) {
            plugin_install($this->plugin_id, $plugin_version, $errors);
        }
    }

    public function activate(
        string $plugin_version,
        array &$errors = []
    ): void {
        if (is_callable('plugin_activate')) {
            plugin_activate($this->plugin_id, $plugin_version, $errors);
        }
    }

    public function deactivate(): void
    {
        if (is_callable('plugin_deactivate')) {
            plugin_deactivate($this->plugin_id);
        }
    }

    public function uninstall(): void
    {
        if (is_callable('plugin_uninstall')) {
            plugin_uninstall($this->plugin_id);
        }
    }

    public function update(
        string $old_version,
        string $new_version,
        array &$errors = []
    ): void {}
}
