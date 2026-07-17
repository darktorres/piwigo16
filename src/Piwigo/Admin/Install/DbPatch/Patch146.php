<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Db\MysqliDb;
use Piwigo\Db\Tables;

/**
 * Former install/db/146-database.php (P23 sub-batch 8g-3).
 */
final class Patch146 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '146';
    }

    #[\Override]
    public function description(): string
    {
        return 'add format_id in history table';
    }

    #[\Override]
    public function apply(): void
    {
        // we use PREFIX_TABLE, in case Piwigo uses an external user table
        MysqliDb::query('
ALTER TABLE `' . Tables::history() . '`
  ADD COLUMN `format_id` int(11) unsigned DEFAULT NULL
;');

        echo "\n" . $this->description() . "\n";
    }
}
