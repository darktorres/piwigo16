<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * Used to declare maintenance methods of a plugin.
 */
class PluginMaintain
{
    protected string $plugin_id;

    public function __construct(
        string $id
    ) {
        $this->plugin_id = $id;
    }

    /**
     * @param array $errors - used to return error messages
     */
    public function install(
        string $plugin_version,
        array &$errors = []
    ): void {}

    /**
     * @param array $errors - used to return error messages
     */
    public function activate(
        string $plugin_version,
        array &$errors = []
    ): void {}

    public function deactivate(): void {}

    public function uninstall(): void {}

    /**
     * @param array $errors - used to return error messages
     */
    public function update(
        string $old_version,
        string $new_version,
        array &$errors = []
    ): void {}

    /**
     * @removed 2.7
     */
    public function autoUpdate(): void
    {
        if (functions_user::is_admin() &&
            ! defined('IN_WS')
        ) {
            trigger_error('Function PluginMaintain::autoUpdate deprecated', E_USER_WARNING);
        }
    }
}
