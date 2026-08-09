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
    /**
     * @param string $theme_id
     */
    public function __construct(
        // Same reasoning as PluginMaintain::$plugin_id.
        // @phpstan-ignore shipmonk.deadProperty.neverRead
        protected $theme_id
    ) {}

    /**
     * @param string $theme_version
     * @param array<int, string> $errors - used to return error messages
     * @return mixed - kept docblock-only here (not native) for the same
     *   third-party-subclass contravariance reason as PluginMaintain::
     *   install() (see Piwigo\Admin\PluginMaintain).
     */
    public function activate($theme_version, &$errors = [])
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
