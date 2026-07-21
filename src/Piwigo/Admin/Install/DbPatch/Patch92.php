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
 * Former install/db/92-database.php (P23 sub-batch 8g-1). Keeps the
 * historical multi-dblayer branches verbatim even though this fork is
 * mysqli-only -- behavior-preserving port, the pgsql/sqlite arms are
 * simply never taken.
 */
final class Patch92 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '92';
    }

    #[\Override]
    public function description(): string
    {
        return 'New colum images.added_by, reference to users.id';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $dblayer = LegacyDbLayer::value();

        // Add column
        $query = 'ALTER TABLE ' . Tables::images() . ' ADD COLUMN ';

        if ($dblayer === 'mysql') {
            $query .= ' added_by smallint(5)';
        }

        if (in_array($dblayer, ['pgsql', 'sqlite', 'pdo-sqlite'])) {
            $query .= ' "added_by" INTEGER default 0';
        }

        $query .= ' NOT NULL;';

        $conn->executeStatement($query);

        // If the webmaster_id has been modified, it must be present in
        // local/config/config.inc.php, so read it from there directly --
        // Config::webmasterId() doesn't see a site's
        // local/config/config.inc.php override on this path (same
        // reasoning as Patch171).
        $localConf = LegacyFileConf::read();
        $webmasterId = $localConf['webmaster_id'] ?? 1;

        // set the existing photos with the webmaster_id as added_by
        $query = 'UPDATE ' . Tables::images() . ' SET added_by = :addedBy;';
        $conn->executeStatement($query, [
            'addedBy' => is_scalar($webmasterId) ? $webmasterId : 1,
        ]);

        echo "\n"
        . $this->description()
        . "\n"
        ;
    }
}
