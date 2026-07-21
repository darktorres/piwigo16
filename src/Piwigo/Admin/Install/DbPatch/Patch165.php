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
    public function apply(Connection $conn): void
    {
        $row = $conn->fetchNumeric('SELECT value FROM ' . Tables::config() . ' WHERE param = "email_admin_on_new_user"');
        $old_value = $row !== false && is_scalar($row[0]) ? (string) $row[0] : '';

        $new_value = 'all';
        if ($old_value === 'false') {
            $new_value = 'none';
        }

        \Piwigo\Config\CurrentConfigService::get()->confUpdateParam('email_admin_on_new_user', $new_value);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
