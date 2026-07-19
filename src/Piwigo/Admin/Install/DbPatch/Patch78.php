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
 * Former install/db/78-database.php (P23 sub-batch 8g-1). Same
 * description-overwritten-with-query quirk as Patch75, preserved.
 */
final class Patch78 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '78';
    }

    #[\Override]
    public function description(): string
    {
        return 'ALTER TABLE ' . Tables::images() . ' add column `md5sum` char(32) default NULL';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $conn->executeStatement($this->description());

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
