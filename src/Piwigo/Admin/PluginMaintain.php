<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin;

/**
 * Used to declare maintenance methods of a plugin.
 */
class PluginMaintain
{
    /**
     * @param string $plugin_id
     */
    public function __construct(
        protected $plugin_id
    ) {}

    /**
     * @param string $plugin_version
     * @param array<int, string> $errors - used to return error messages
     * @return mixed - matches DummyPluginMaintain::install()'s committed native
     *   `mixed` return; kept docblock-only here (not native) since a native
     *   return type on this base class would break any real third-party plugin
     *   maintain.class.php subclass whose own override declares no return type
     *   at all (verified empirically: PHP fatals on such a mismatch)
     */
    public function install($plugin_version, &$errors = [])
    {
        return null;
    }

    /**
     * @param string $plugin_version
     * @param array<int, string> $errors - used to return error messages
     * @return mixed - see install()'s @return docblock
     */
    public function activate($plugin_version, &$errors = [])
    {
        return null;
    }

    /**
     * @return mixed - see install()'s @return docblock
     */
    public function deactivate()
    {
        return null;
    }

    /**
     * @return mixed - see install()'s @return docblock
     */
    public function uninstall()
    {
        return null;
    }

    /**
     * @param string $old_version
     * @param string $new_version
     * @param array<int, string> $errors - used to return error messages
     * matches DummyPluginMaintain::update()'s committed native `void`
     * return; written as the phpstan-only variant of the return tag below
     * because ECS's phpdoc_no_empty_return fixer strips the generic form
     * of that tag, which would leave this method's return type undeclared
     * again
     * @phpstan-return void
     */
    public function update($old_version, $new_version, &$errors = []) {}

    /**
     * @removed 2.7
     */
    public function autoUpdate(): void
    {
        if (\Piwigo\Auth\AccessControl::current()->isAdmin() && ! \Piwigo\Core\WsContext::isActiveStatic()) {
            trigger_error('Function PluginMaintain::autoUpdate deprecated', E_USER_WARNING);
        }
    }
}
