<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Piwigo\Config\ConfigDb;
use Piwigo\Db\MysqliDb;
use Piwigo\Db\Tables;

/**
 * Former install/db/165-database.php (P23 sub-batch 8g-3).
 */
final class Patch165 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '165';
    }

    #[\Override]
    public function description(): string
    {
        return 'Add more options to email_admin_on_new_user';
    }

    #[\Override]
    public function apply(): void
    {
        [$old_value] = MysqliDb::fetchRow(MysqliDb::query('SELECT value FROM ' . Tables::config() . ' WHERE param = "email_admin_on_new_user"'));

        $new_value = 'all';
        if ($old_value == 'false') {
            $new_value = 'none';
        }

        ConfigDb::confUpdateParam('email_admin_on_new_user', $new_value);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
