<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Db\DbConnection;

final class MaintenanceService
{
    public static function repairAndOptimize(): void
    {
        $prefixeTable = is_string($GLOBALS['prefixeTable'] ?? null) ? $GLOBALS['prefixeTable'] : 'piwigo_';
        $conn = DbConnection::get();

        $allTables = $conn->executeQuery("SHOW TABLES LIKE '" . $prefixeTable . "%'")->fetchFirstColumn();
        if ($allTables === []) {
            return;
        }

        $success = true;
        try {
            $allTablesStr = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $allTables);
            $conn->executeStatement('REPAIR TABLE ' . implode(', ', $allTablesStr));

            foreach ($allTablesStr as $tableName) {
                $primaryKeys = [];
                foreach ($conn->executeQuery('DESC ' . $tableName)->fetchAllAssociative() as $row) {
                    if ($row['Key'] === 'PRI') {
                        $primaryKeys[] = is_scalar($row['Field']) ? (string) $row['Field'] : '';
                    }
                }
                if ($primaryKeys !== []) {
                    $conn->executeStatement('ALTER TABLE ' . $tableName . ' ORDER BY ' . implode(', ', $primaryKeys));
                }
            }

            $conn->executeStatement('OPTIMIZE TABLE ' . implode(', ', $allTablesStr));
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
