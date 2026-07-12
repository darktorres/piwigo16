<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

final class PluginRepository extends AbstractRepository
{
    /**
     * Returns plugins defined in the database, optionally filtered by state
     * and/or id. Bound parameters replace the original get_db_plugins()'s
     * raw string-concatenated WHERE clause (no addslashes() even).
     *
     * @return list<array<string, string|null>> - this driver never enables
     *   MYSQLI_OPT_INT_AND_FLOAT_NATIVE, so every column comes back as
     *   string|null
     */
    public function getDbPlugins(string $state = '', string $id = ''): array
    {
        $query = 'SELECT * FROM ' . Tables::plugins();
        $clauses = [];
        $params = [];

        if ($state !== '') {
            $clauses[] = 'state = ?';
            $params[] = $state;
        }
        if ($id !== '') {
            $clauses[] = 'id = ?';
            $params[] = $id;
        }
        if ($clauses !== []) {
            $query .= ' WHERE ' . implode(' AND ', $clauses);
        }

        /** @var list<array<string, string|null>> */
        return $this->conn->fetchAllAssociative($query, $params);
    }

    /**
     * Records a plugin's filesystem version after autoupdate_plugin()
     * detects a newer main.inc.php header -- bound parameters replace the
     * original's raw string-concatenated UPDATE.
     */
    public function updateVersion(string $id, string $version): void
    {
        $this->conn->executeStatement(
            'UPDATE ' . Tables::plugins() . ' SET version = ? WHERE id = ?',
            [$version, $id],
        );
    }
}
