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
 * Used to declare maintenance methods of a theme.
 */
class ThemeMaintain
{
    public function __construct(
        // Same reasoning as PluginMaintain::$plugin_id.
        // @phpstan-ignore shipmonk.deadProperty.neverRead
        protected string $theme_id
    ) {}

    /**
     * @param array<int, string> $errors - used to return error messages
     * @return mixed - kept docblock-only here (not native) for the same
     *   third-party-subclass contravariance reason as PluginMaintain::
     *   install() (see Piwigo\Admin\PluginMaintain).
     */
    public function activate(string $theme_version, array &$errors = [])
    {
        return null;
    }

    /**
     * @return mixed - see activate()'s @return docblock
     */
    public function deactivate()
    {
        return null;
    }

    /**
     * @return mixed - see activate()'s @return docblock
     */
    public function delete()
    {
        return null;
    }
}
