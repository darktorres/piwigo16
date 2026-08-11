<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin;

use Piwigo\Auth\AccessControl;
use Piwigo\Core\WsContext;

/**
 * Used to declare maintenance methods of a plugin.
 */
class PluginMaintain
{
    public function __construct(
        // protected so real third-party plugin subclasses can read it.
        // @phpstan-ignore shipmonk.deadProperty.neverRead
        protected string $plugin_id,
        private readonly WsContext $wsContext,
        private readonly AccessControl $accessControl,
    ) {}

    /**
     * @param array<int, string> $errors - used to return error messages
     * @return mixed - kept docblock-only here (not native) since a native
     *   return type on this base class would break any real third-party plugin
     *   maintain.class.php subclass whose own override declares no return type
     *   at all (verified empirically: PHP fatals on such a mismatch)
     */
    public function install(string $plugin_version, array &$errors = [])
    {
        return null;
    }

    /**
     * @param array<int, string> $errors - used to return error messages
     * @return mixed - see install()'s @return docblock
     */
    public function activate(string $plugin_version, array &$errors = [])
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
     * @param array<int, string> $errors - used to return error messages
     * written as the phpstan-only variant of the return tag below because
     * ECS's phpdoc_no_empty_return fixer strips the generic form of that
     * tag, which would leave this method's return type undeclared again
     * @phpstan-return void
     */
    public function update(string $old_version, string $new_version, array &$errors = []) {}

    /**
     * @removed 2.7
     */
    public function autoUpdate(): void
    {
        if ($this->accessControl->isAdmin() && ! $this->wsContext->isActive()) {
            trigger_error('Function PluginMaintain::autoUpdate deprecated', E_USER_WARNING);
        }
    }
}
