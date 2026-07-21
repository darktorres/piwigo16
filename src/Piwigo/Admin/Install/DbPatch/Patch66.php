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
 * Former install/db/66-database.php (P23 sub-batch 8g-1).
 */
final class Patch66 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '66';
    }

    #[\Override]
    public function description(): string
    {
        return 'Add c13y_ignore config';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $batchWriter = new BatchWriter($conn);
        $batchWriter->singleInsert(Tables::config(), [
            'param' => 'c13y_ignore',
            'value' => null,
            'comment' => 'List of ignored anomalies',
        ]);

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
