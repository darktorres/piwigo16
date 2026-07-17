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
 * Former install/db/62-database.php (P23 sub-batch 8g-1).
 */
final class Patch62 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '62';
    }

    #[\Override]
    public function description(): string
    {
        return 'Add obligatory_user_mail config';
    }

    #[\Override]
    public function apply(): void
    {
        $query = '
INSERT INTO ' . Tables::config() . " (param,value,comment) VALUES ('obligatory_user_mail_address','false','Mail address is obligatory for users');
";
        MysqliDb::query($query);

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
