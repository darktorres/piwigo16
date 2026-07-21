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
 * Former install/db/106-database.php (P23 sub-batch 8g-2). Reads the
 * file-config order_by/order_by_inside_category values via
 * LegacyFileConf::read() to seed the DB rows -- Config::orderBy()/
 * Config::orderByInsideCategory() don't see a site's
 * local/config/config.inc.php override on this path (same reasoning as
 * ConfigurationSubController::orderByIsLocal()).
 */
final class Patch106 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '106';
    }

    #[\Override]
    public function description(): string
    {
        return 'add order parameters to bdd';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $localConf = LegacyFileConf::read();
        $orderBy = is_scalar($localConf['order_by'] ?? null) ? $localConf['order_by'] : '';
        $orderByInsideCategory = is_scalar($localConf['order_by_inside_category'] ?? null) ? $localConf['order_by_inside_category'] : '';

        $query = '
INSERT INTO ' . Tables::config() . '(param,value,comment)
  VALUES (\'order_by\', \'' . $orderBy . '\', \'default photos order\')
;';
        $conn->executeStatement($query);

        $query = '
INSERT INTO ' . Tables::config() . '(param,value,comment)
  VALUES (\'order_by_inside_category\', \'' . $orderByInsideCategory . '\', \'default photos order inside category\')
;';
        $conn->executeStatement($query);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
