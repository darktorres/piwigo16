<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin\inc;

use Piwigo\inc\ThemeMaintain;

/**
 * used when a theme uses the old procedural declaration of maintenance methods
 */
class DummyTheme_maintain extends ThemeMaintain
{
    public function activate(
        string $theme_version,
        array &$errors = []
    ): void {
        if (is_callable('theme_activate')) {
            theme_activate($this->theme_id, $theme_version, $errors);
        }
    }

    public function deactivate(): void
    {
        if (is_callable('theme_deactivate')) {
            theme_deactivate($this->theme_id);
        }
    }

    public function delete(): void
    {
        if (is_callable('theme_delete')) {
            theme_delete($this->theme_id);
        }
    }
}
