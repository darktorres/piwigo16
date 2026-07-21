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
use Piwigo\Core\ArrayHelper;
use Piwigo\Db\Tables;

/**
 * Former install/db/181-database.php (P23 sub-batch 8g-3) -- the last
 * upstream one-shot patch. The bare safe_unserialize() became
 * ArrayHelper::safeUnserialize() (real fix for the broken-at-HEAD
 * coupling, same as Patch177). The former `isset($conf['filters_views'])`
 * existence check (Legacy Coupling Retirement Phase 8, 8d) moved onto a
 * direct row fetch for the same reason as Patch104's: Config::has() can't
 * distinguish "no DB row" from "SCHEMA default only" now that Config::$data
 * is pre-populated with every SCHEMA default at boot. $conf stays global
 * for default_filters_views -- still genuinely file-config-sourced (same
 * InstallWizard precedent as Patch94/Patch119's data_location reads).
 */
final class Patch181 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '181';
    }

    #[\Override]
    public function description(): string
    {
        return 'add "expert mode" in filters_views for gallery search';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $configService = \Piwigo\Config\CurrentConfigService::get();
        $configService->loadConfFromDb();

        // if the filters_views is not already registered in the config table, no need
        // to update it because it will be initialized with all filters
        $row = $conn->fetchAssociative('SELECT value FROM ' . Tables::config() . ' WHERE param = :param', [
            'param' => 'filters_views',
        ]);
        if ($row !== false) {
            $filtersViews = ArrayHelper::safeUnserialize(is_scalar($row['value']) ? (string) $row['value'] : '');

            if (is_array($filtersViews) && ! isset($filtersViews['expert'])) {
                $filtersViews['expert'] = $conf['default_filters_views']['expert'];
                $configService->confUpdateParam('filters_views', $filtersViews);
            }
        }

        echo "\n" . $this->description() . "\n";
    }
}
