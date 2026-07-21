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
        protected $theme_id
    ) {}

    /**
     * @param string $theme_version
     * @param array<int, string> $errors - used to return error messages
     * @return mixed - matches DummyThemeMaintain::activate()'s committed
     *   native `mixed` return; kept docblock-only here (not native) for the
     *   same third-party-subclass contravariance reason as PluginMaintain
     *   above.
     */
    public function activate($theme_version, &$errors = []) {}

    /**
     * @return mixed - see activate()'s @return docblock
     */
    public function deactivate() {}

    /**
     * @return mixed - see activate()'s @return docblock
     */
    public function delete() {}
}
