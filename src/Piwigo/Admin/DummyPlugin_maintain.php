<?php

declare(strict_types=1);

namespace Piwigo\Admin;

/**
 * class DummyPlugin_maintain
 * used when a plugin uses the old procedural declaration of maintenance methods.
 * Old-style plugins define plugin_install(), plugin_activate(), etc. as global functions.
 */
class DummyPlugin_maintain extends PluginMaintain
{
    public function install($plugin_version, &$errors = [])
    {
        return plugin_install($this->plugin_id, $plugin_version, $errors);
    }
    public function activate($plugin_version, &$errors = [])
    {
        return plugin_activate($this->plugin_id, $plugin_version, $errors);
    }
    public function deactivate()
    {
        return plugin_deactivate($this->plugin_id);
    }
    public function uninstall()
    {
        return plugin_uninstall($this->plugin_id);
    }
    public function update($old_version, $new_version, &$errors = [])
    {
    }
}
