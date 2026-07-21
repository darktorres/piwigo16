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
 * Former install/db/105-database.php (P23 sub-batch 8g-2).
 */
final class Patch105 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '105';
    }

    #[\Override]
    public function description(): string
    {
        return 'Show menubar on picture page';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $batchWriter = new BatchWriter($conn);
        $batchWriter->singleInsert(Tables::config(), [
            'param' => 'picture_menu',
            'value' => 'false',
            'comment' => $this->description(),
        ]);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
