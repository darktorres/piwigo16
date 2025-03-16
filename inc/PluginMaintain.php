<?php

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
    /**
     * @var string
     */
    protected $plugin_id;

    /**
     * @param string $id
     */
    public function __construct($id)
    {
        $this->plugin_id = $id;
    }

    /**
     * @param string $plugin_version
     * @param array $errors - used to return error messages
     */
    public function install($plugin_version, &$errors = []) {}

    /**
     * @param string $plugin_version
     * @param array $errors - used to return error messages
     */
    public function activate($plugin_version, &$errors = []) {}

    public function deactivate() {}

    public function uninstall() {}

    /**
     * @param string $old_version
     * @param string $new_version
     * @param array $errors - used to return error messages
     */
    public function update($old_version, $new_version, &$errors = []) {}

    /**
     * @removed 2.7
     */
    public function autoUpdate()
    {
        if (functions_user::is_admin() && ! defined('IN_WS')) {
            trigger_error('Function PluginMaintain::autoUpdate deprecated', E_USER_WARNING);
        }
    }
}
