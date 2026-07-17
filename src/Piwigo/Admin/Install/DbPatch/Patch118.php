<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Admin\themes;

/**
 * Former install/db/118-database.php (P23 sub-batch 8g-2).
 */
final class Patch118 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '118';
    }

    #[\Override]
    public function description(): string
    {
        return 'Automatically activate mobile theme.';
    }

    #[\Override]
    public function apply(): void
    {
        $themes = new themes();
        $themes->perform_action('activate', 'smartpocket');

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
