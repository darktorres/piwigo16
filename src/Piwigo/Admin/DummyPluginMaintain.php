<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin;

use Override;

/**
 * used when a plugin uses the old procedural declaration of maintenance methods
 */
final class DummyPluginMaintain extends PluginMaintain
{
    // Each is_callable() here checks for a bare function dynamically defined
    // by a plugin's own maintain.inc.php (include_once'd in
    // ExtensionLifecycle, outside this codebase, only known at runtime --
    // see phpstan.neon's function.impossibleType/function.notFound entry for
    // why PHPStan can't be told about this instead of suppressing here).
    /**
     * @param string $plugin_version
     * @param array<int, string> $errors - not natively typed: PluginMaintain's
     *   own base declares $errors with no native type, and PHP's parameter
     *   contravariance rules fatal on narrowing an untyped parent param to a
     *   native type in the override (verified empirically)
     */
    #[Override]
    public function install($plugin_version, &$errors = []): mixed
    {
        if (is_callable('plugin_install')) {
            return plugin_install($this->plugin_id, $plugin_version, $errors);
        }

        return null;
    }

    /**
     * @param string $plugin_version
     * @param array<int, string> $errors - see install()'s $errors docblock
     */
    #[Override]
    public function activate($plugin_version, &$errors = []): mixed
    {
        if (is_callable('plugin_activate')) {
            return plugin_activate($this->plugin_id, $plugin_version, $errors);
        }

        return null;
    }

    #[Override]
    public function deactivate(): mixed
    {
        if (is_callable('plugin_deactivate')) {
            return plugin_deactivate($this->plugin_id);
        }

        return null;
    }

    #[Override]
    public function uninstall(): mixed
    {
        if (is_callable('plugin_uninstall')) {
            return plugin_uninstall($this->plugin_id);
        }

        return null;
    }

    /**
     * @param string $old_version
     * @param string $new_version
     * @param array<int, string> $errors - see install()'s $errors docblock
     */
    #[Override]
    public function update($old_version, $new_version, &$errors = []): void {}
}
