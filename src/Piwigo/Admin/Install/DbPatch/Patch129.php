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
 * Former install/db/129-database.php (P23 sub-batch 8g-2).
 */
final class Patch129 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '129';
    }

    #[\Override]
    public function description(): string
    {
        return 'add "website_url" field in comments table';
    }

    #[\Override]
    public function apply(): void
    {
        $query = 'ALTER TABLE `' . Tables::comments() . '` ADD `website_url` varchar(255) DEFAULT NULL;';
        MysqliDb::query($query);

        echo "\n" . $this->description() . "\n";
    }
}
