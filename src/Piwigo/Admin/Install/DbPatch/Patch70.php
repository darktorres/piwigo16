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
use Piwigo\Core\AccessLevel;
use Piwigo\Db\Tables;

/**
 * Former install/db/70-database.php (P23 sub-batch 8g-1). ACCESS_CLASSIC
 * (from the long-deleted include/constants.php) became
 * AccessLevel::Classic -- same value 2.
 */
final class Patch70 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '70';
    }

    #[\Override]
    public function description(): string
    {
        return 'Add upload config';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = '
replace into ' . Tables::config() . '
  (param, value, comment)
values
(:param1, :value1, :comment1),
(:param2, :value2, :comment2)
;';
        $conn->executeStatement($query, [
            'param1' => 'upload_link_everytime',
            'value1' => 'false',
            'comment1' => 'Show Upload link every time',
            'param2' => 'upload_user_access',
            'value2' => AccessLevel::Classic,
            'comment2' => 'Minimal user status allowed to upload pictures',
        ]);

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
