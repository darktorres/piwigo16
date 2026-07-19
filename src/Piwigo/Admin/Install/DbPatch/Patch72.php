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
 * Former install/db/72-database.php (P23 sub-batch 8g-1).
 */
final class Patch72 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '72';
    }

    #[\Override]
    public function description(): string
    {
        return 'Add extents_for_templates config';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $query = '
INSERT INTO ' . Tables::config() . " (param,value,comment) VALUES ('extents_for_templates','a:0:{}','Actived template-extension(s)');
";
        $conn->executeStatement($query);

        echo "\n"
        . '"' . $this->description() . '" ended'
        . "\n"
        ;
    }
}
