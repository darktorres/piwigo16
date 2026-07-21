<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Doctrine\DBAL\Connection;
use Piwigo\Db\Tables;

/**
 * Former install/db/102-database.php (P23 sub-batch 8g-2).
 */
final class Patch102 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '102';
    }

    #[\Override]
    public function description(): string
    {
        return 'change nb_image_page into smallint(3)';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        // add column
        if (LegacyDbLayer::value() === 'mysql') {
            $conn->executeStatement('
    ALTER TABLE ' . Tables::userInfos() . '
      CHANGE `nb_image_page` `nb_image_page` SMALLINT(3) UNSIGNED NOT NULL DEFAULT 15
  ;');
        }

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
