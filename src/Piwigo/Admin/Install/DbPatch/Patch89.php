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
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;

/**
 * Former install/db/89-database.php (P23 sub-batch 8g-1).
 */
final class Patch89 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '89';
    }

    #[\Override]
    public function description(): string
    {
        return 'Admins can activate/deactivate user customization.';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $batchWriter = new BatchWriter($conn);
        $batchWriter->singleInsert(Tables::config(), [
            'param' => 'allow_user_customization',
            'value' => 'true',
            'comment' => 'allow users to customize their gallery?',
        ]);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
