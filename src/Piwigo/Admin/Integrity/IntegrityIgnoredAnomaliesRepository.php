<?php

declare(strict_types=1);

namespace Piwigo\Admin\Integrity;

use Doctrine\DBAL\Connection;
use Piwigo\Db\Tables;

/**
 * Persistence for the "ignore this anomaly" list the admin manages in
 * the Maintenance → Integrity check page.
 *
 * Replaces the legacy `piwigo_conf.c13y_ignore` row (which held a
 * PHP-serialized `{version, list}` blob). Each (anomaly_id, version)
 * pair is one row; the version is the piwigo version the admin was
 * running when they ignored the anomaly. Reads are scoped to the
 * current version, so a major upgrade resurfaces ignored anomalies
 * (matching the legacy semantics).
 */
final readonly class IntegrityIgnoredAnomaliesRepository
{
    public function __construct(private Connection $conn)
    {
    }

    /** @return list<string> anomaly IDs ignored for the given piwigo version */
    public function listForVersion(string $piwigoVersion): array
    {
        /** @var list<string> $rows */
        $rows = $this->conn->executeQuery(
            'SELECT anomaly_id FROM ' . Tables::integrityIgnoredAnomalies() . ' WHERE piwigo_version = ?',
            [$piwigoVersion]
        )->fetchFirstColumn();
        return $rows;
    }

    /**
     * Replace the entire ignored-anomaly set with the given list, scoped
     * to a single piwigo version. Earlier versions' rows are cleared
     * too — the legacy conf row stored a single {version, list} so this
     * matches its "whole row replace" semantics.
     *
     * @param list<string> $anomalyIds
     */
    public function replaceForVersion(string $piwigoVersion, array $anomalyIds): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::integrityIgnoredAnomalies());

        if ($anomalyIds === []) {
            return;
        }

        foreach ($anomalyIds as $id) {
            $this->conn->executeStatement(
                'INSERT IGNORE INTO ' . Tables::integrityIgnoredAnomalies()
                . ' (anomaly_id, piwigo_version, ignored_at) VALUES (?, ?, NOW())',
                [$id, $piwigoVersion]
            );
        }
    }

    public function clearAll(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::integrityIgnoredAnomalies());
    }
}
