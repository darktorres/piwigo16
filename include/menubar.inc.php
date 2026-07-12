<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Menu\MenubarRenderer;

initialize_menu();

/**
 * Setups each block the main menubar.
 */
function initialize_menu(): void
{
    new MenubarRenderer()
        ->render();
}
