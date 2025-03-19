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
 * Used to declare maintenance methods of a theme.
 */
class ThemeMaintain
{
    protected string $theme_id;

    public function __construct(
        string $id
    ) {
        $this->theme_id = $id;
    }

    /**
     * @param array $errors - used to return error messages
     */
    public function activate(
        string $theme_version,
        array &$errors = []
    ): void {}

    public function deactivate(): void {}

    public function delete(): void {}
}
