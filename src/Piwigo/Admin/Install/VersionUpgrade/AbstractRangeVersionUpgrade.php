<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\VersionUpgrade;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Install\DbPatch\DbPatchRegistry;
use Piwigo\Admin\Install\UpgradeService;
use Piwigo\Core\AppInfo;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;

/**
 * Shared body of the "range" version-upgrade steps (1.7.0 and every 2.x+
 * script): each marked all pre-range patch ids as "not applied" in the
 * ledger, then ran its numbered patch range with a per-id ledger INSERT,
 * inside an ob_start()/ob_end_clean() pair that deliberately swallowed
 * the patches' progress echoes. The originals were 17 near-identical
 * copies differing only in the range bounds and version label; the OOP
 * port parameterizes exactly those.
 *
 * Two broken-at-HEAD couplings are repaired here for the whole family:
 * the ledger descriptions read PHPWG_VERSION and the inserts used
 * UPGRADE_TABLE -- constants nothing has defined for phases (documented
 * in the 8f-6 review) -- now AppInfo::VERSION and Tables::upgrade().
 * CURRENT_DATE is still read as a constant: the upgrade.php entry shell
 * define()s it on this path (SEC-60 keeps the define out of src/).
 */
abstract class AbstractRangeVersionUpgrade implements VersionUpgradeInterface
{
    /**
     * Marks every not-yet-applied patch id up to $maxIdInclusive as
     * "[migration from X to <version>] not applied" in the ledger.
     * (Original shape: `if ($upgrade_id > 60) break;` in 1.7.0,
     * `if ($upgrade_id >= 81) break;` in 2.0.0 -- both are an inclusive
     * upper bound over the natcasesort-ordered id list.)
     */
    protected function markPreRangeNotApplied(Connection $conn, int $maxIdInclusive): void
    {
        // retrieve already applied upgrades
        $query = '
SELECT id
  FROM ' . Tables::upgrade() . '
;';
        $applied = array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            $conn->fetchFirstColumn($query)
        );

        // retrieve existing upgrades
        $existing = UpgradeService::getAvailableUpgradeIds();

        // which upgrades need to be applied?
        $to_apply = array_diff($existing, $applied);
        $inserts = [];
        foreach ($to_apply as $upgrade_id) {
            if ((int) $upgrade_id > $maxIdInclusive) {
                break;
            }

            array_push(
                $inserts,
                [
                    'id' => $upgrade_id,
                    'applied' => CURRENT_DATE,
                    'description' => '[migration from ' . $this->versionFrom() . ' to ' . AppInfo::VERSION . '] not applied',
                ]
            );
        }

        if (! empty($inserts)) {
            new BatchWriter($conn)
                ->massInsert(
                    '`' . Tables::upgrade() . '`',
                    array_keys($inserts[0]),
                    $inserts
                );
        }
    }

    /**
     * Applies patch ids $firstId..$lastId in order, recording each in the
     * ledger with the '[migration from X to <version>] <description>'
     * text. The originals' file_exists() break doubled as range-end
     * detection; the registry carries every id, so has() keeps the exact
     * same guard shape.
     */
    protected function runPatchRange(Connection $conn, int $firstId, int $lastId): void
    {
        ob_start();
        echo '<pre>';

        for ($upgrade_id = $firstId; $upgrade_id <= $lastId; $upgrade_id++) {
            if (! DbPatchRegistry::has((string) $upgrade_id)) {
                break;
            }

            echo "\n\n";
            echo '=== upgrade ' . $upgrade_id . "\n";

            $patch = DbPatchRegistry::make((string) $upgrade_id);
            $patch->apply($conn);

            // notify upgrade
            $query = '
INSERT INTO `' . Tables::upgrade() . '`
  (id, applied, description)
  VALUES
  (\'' . $upgrade_id . '\', NOW(), \'[migration from ' . $this->versionFrom() . ' to ' . AppInfo::VERSION . '] ' . $patch->description() . '\')
;';
            $conn->executeStatement($query);
        }

        echo '</pre>';
        ob_end_clean();
    }
}
