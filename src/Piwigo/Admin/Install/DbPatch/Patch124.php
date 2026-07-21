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
 * Former install/db/124-database.php (P23 sub-batch 8g-2).
 */
final class Patch124 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '124';
    }

    #[\Override]
    public function description(): string
    {
        return 'derivatives: remove useless configuration settings and columns';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        //
        // Clean useless configuration settings
        //
        $conn->executeStatement('DELETE FROM ' . Tables::config() . ' WHERE param like :pattern;', [
            'pattern' => 'upload_form_%',
        ]);

        //
        // Remove useless columns
        //

        $query = '
ALTER TABLE ' . Tables::userInfos() . '
  DROP `maxwidth`,
  DROP `maxheight`
;';
        $conn->executeStatement($query);

        $query = '
ALTER TABLE ' . Tables::images() . '
  DROP `high_width`,
  DROP `high_height`,
  DROP `high_filesize`,
  DROP `has_high`,
  DROP `tn_ext`
;';
        $conn->executeStatement($query);

        echo "\n" . $this->description() . "\n";
    }
}
