<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Db\DbMaintenanceRepository;

final class MaintenanceService
{
    public static function repairAndOptimize(): void
    {
        $repo = Kernel::service(DbMaintenanceRepository::class);

        $allTables = $repo->findAllPiwigoTableNames();
        if ($allTables === []) {
            return;
        }

        $success = true;
        try {
            $repo->repairTables($allTables);

            foreach ($allTables as $tableName) {
                $primaryKeys = $repo->findPrimaryKeyColumns($tableName);
                $repo->reorderTableByPrimaryKeys($tableName, $primaryKeys);
            }

            $repo->optimizeTables($allTables);
        } catch (\Exception) {
            $success = false;
        }

        if ($success) {
            PageState::current()->addInfo(Lang::t('All optimizations have been successfully completed.'));
        } else {
            PageState::current()->addError(Lang::t('Optimizations have been completed with some errors.'));
        }
    }
}
