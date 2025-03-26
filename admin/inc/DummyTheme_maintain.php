<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin\inc;

use Override;
use Piwigo\inc\ThemeMaintain;

/**
 * used when a theme uses the old procedural declaration of maintenance methods
 */
final class DummyTheme_maintain extends ThemeMaintain
{
    #[Override]
    public function activate(
        string $theme_version,
        array &$errors = []
    ): void {
        if (is_callable('theme_activate')) {
            theme_activate($this->theme_id, $theme_version, $errors);
        }
    }

    #[Override]
    public function deactivate(): void
    {
        if (is_callable('theme_deactivate')) {
            theme_deactivate($this->theme_id);
        }
    }

    #[Override]
    public function delete(): void
    {
        if (is_callable('theme_delete')) {
            theme_delete($this->theme_id);
        }
    }
}
