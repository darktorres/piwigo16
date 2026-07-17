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
 * Former install/db/162-database.php (P23 sub-batch 8g-3).
 */
final class Patch162 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '162';
    }

    #[\Override]
    public function description(): string
    {
        return 'change activity.performed_by for logout';
    }

    #[\Override]
    public function apply(): void
    {
        $query = '
UPDATE ' . Tables::activity() . '
  SET performed_by = object_id
  WHERE action = \'logout\'
;';
        MysqliDb::query($query);

        echo "\n" . $this->description() . "\n";
    }
}
