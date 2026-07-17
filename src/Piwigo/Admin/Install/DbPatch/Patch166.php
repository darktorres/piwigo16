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
 * Former install/db/166-database.php (P23 sub-batch 8g-3).
 */
final class Patch166 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '166';
    }

    #[\Override]
    public function description(): string
    {
        return 'Create new columns for search (search_uuid, created_on, user_idx, forked_from).';
    }

    #[\Override]
    public function apply(): void
    {
        MysqliDb::query('
ALTER TABLE `' . Tables::search() . '`
  ADD COLUMN `search_uuid` CHAR(23) DEFAULT NULL,
  ADD COLUMN `created_on` DATETIME DEFAULT NULL,
  ADD COLUMN `created_by` MEDIUMINT(8) UNSIGNED,
  ADD COLUMN `forked_from` INT(10) UNSIGNED
;');

        echo "\n" . $this->description() . "\n";
    }
}
