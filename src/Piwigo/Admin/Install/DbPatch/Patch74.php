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
 * Former install/db/74-database.php (P23 sub-batch 8g-1). `blk_menubar`
 * itself is confirmed unread anywhere in `src/Piwigo/`/`themes/` today,
 * so `BatchWriter::singleInsert()`'s null-coercion of an empty-string
 * value (see its own docblock) is harmless here -- nothing consumes the
 * distinction between `''` and `NULL` for this specific dead key.
 */
final class Patch74 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '74';
    }

    #[\Override]
    public function description(): string
    {
        return 'Add blk_menubar config';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $batchWriter = new BatchWriter($conn);
        $batchWriter->singleInsert(Tables::config(), [
            'param' => 'blk_menubar',
            'value' => '',
            'comment' => 'Menubar options',
        ]);

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
