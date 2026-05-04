<?php

declare(strict_types=1);

namespace Piwigo\Admin;

/**
 * class DummyPluginMaintain
 * used when a plugin uses the old procedural declaration of maintenance methods.
 * Old-style plugins define plugin_install(), plugin_activate(), etc. as global functions.
 *
 * Bare `plugin_install()` would resolve to `Piwigo\Admin\plugin_install` because
 * of the namespace context — the leading `\` forces global lookup. Old plugins
 * commonly only define a subset of the four hooks (e.g. only plugin_uninstall);
 * each call is guarded with function_exists() so absent hooks no-op instead of
 * fataling on activation.
 */
class DummyPluginMaintain extends PluginMaintain
{
    /** @param string $plugin_version
     *  @param array<mixed> $errors */
    public function install($plugin_version, &$errors = []): null
    {
        if (!function_exists('plugin_install')) {
            return null;
        }
        trigger_error('plugin_install() is deprecated; extend PluginMaintain instead', E_USER_DEPRECATED);
        \plugin_install($this->plugin_id, $plugin_version, $errors);
        return null;
    }

    /** @param string $plugin_version
     *  @param array<mixed> $errors */
    public function activate($plugin_version, &$errors = []): null
    {
        if (!function_exists('plugin_activate')) {
            return null;
        }
        trigger_error('plugin_activate() is deprecated; extend PluginMaintain instead', E_USER_DEPRECATED);
        \plugin_activate($this->plugin_id, $plugin_version, $errors);
        return null;
    }

    public function deactivate(): void
    {
        if (!function_exists('plugin_deactivate')) {
            return;
        }
        trigger_error('plugin_deactivate() is deprecated; extend PluginMaintain instead', E_USER_DEPRECATED);
        \plugin_deactivate($this->plugin_id);
    }

    public function uninstall(): void
    {
        if (!function_exists('plugin_uninstall')) {
            return;
        }
        trigger_error('plugin_uninstall() is deprecated; extend PluginMaintain instead', E_USER_DEPRECATED);
        \plugin_uninstall($this->plugin_id);
    }

    /** @param string $old_version
     *  @param string $new_version
     *  @param array<mixed> $errors */
    public function update($old_version, $new_version, &$errors = []): null
    {
        return null;
    }
}
