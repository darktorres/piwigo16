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
    public function activate($theme_version, &$errors = [])
    {
        if (is_callable('theme_activate')) {
            return theme_activate($this->theme_id, $theme_version, $errors);
        }
    }

    public function deactivate()
    {
        if (is_callable('theme_deactivate')) {
            return theme_deactivate($this->theme_id);
        }
    }

    public function delete()
    {
        if (is_callable('theme_delete')) {
            return theme_delete($this->theme_id);
        }
    }
}
