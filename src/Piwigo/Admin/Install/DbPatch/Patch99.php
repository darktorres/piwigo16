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
 * Former install/db/99-database.php (P23 sub-batch 8g-1).
 */
final class Patch99 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '99';
    }

    #[\Override]
    public function description(): string
    {
        return 'delete the config parameter comments_update_validation';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = 'DELETE FROM ' . Tables::config() . ' WHERE param = :param;';

        $conn->executeStatement($query, [
            'param' => 'comments_update_validation',
        ]);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
