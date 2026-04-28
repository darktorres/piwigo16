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
    /** @param array<mixed> $errors */
    public function install(string $plugin_version, array &$errors = []): mixed
    {
        return plugin_install($this->plugin_id, $plugin_version, $errors);
    }
    /** @param array<mixed> $errors */
    public function activate(string $plugin_version, array &$errors = []): mixed
    {
        return plugin_activate($this->plugin_id, $plugin_version, $errors);
    }
    public function deactivate(): void
    {
        plugin_deactivate($this->plugin_id);
    }
    public function uninstall(): void
    {
        plugin_uninstall($this->plugin_id);
    }
    /** @param array<mixed> $errors */
    public function update(string $old_version, string $new_version, array &$errors = []): mixed
    {
        return null;
    }
}
