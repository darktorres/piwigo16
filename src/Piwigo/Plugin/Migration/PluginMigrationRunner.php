<?php

declare(strict_types=1);

namespace Piwigo\Plugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\LoggerInterface;

/**
 * Runs Doctrine-style migrations shipped by a plugin under
 * `plugins/<id>/migrations/Version*.php`. Each migration class extends
 * `Doctrine\Migrations\AbstractMigration` exactly like the core ones,
 * but instead of going through Doctrine's full Migrator/MetadataStorage
 * stack (which assumes a single migrations set per DB), execution is
 * handled here so we can key applied migrations on (plugin_id, version)
 * via [[PluginMigrationLedger]].
 *
 * Why not Doctrine's own runner?
 * - Doctrine's metadata storage uses a single-column `version` PK; two
 *   plugins shipping the same FQCN would collide.
 * - Plugins shouldn't need to know about DependencyFactory or
 *   ConfigurationLoader to ship a schema change.
 *
 * What this loses vs the full Doctrine runner:
 * - No interactive CLI; runs happen on activate/update/uninstall.
 * - No `--dry-run`; the registry tests the failure path, not preview.
 * - No migration generation; plugin authors write the Version*.php
 *   files by hand.
 *
 * What it keeps:
 * - `addSql()` queueing and `getSql()` collection.
 * - `preUp`/`up`/`postUp` (and `down` symmetrically).
 * - `isTransactional()` honored: when true the SQL batch runs inside a
 *   `beginTransaction`/`commit` envelope.
 */
final class PluginMigrationRunner
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PluginMigrationLedger $ledger,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Apply every un-applied migration for $pluginId, in version order.
     *
     * @return list<string> FQCNs that were applied in this call.
     */
    public function runUp(
        string $pluginId,
        ?string $namespace,
        ?string $migrationsDir,
    ): array {
        if ($namespace === null || $migrationsDir === null) {
            return [];
        }
        if (!is_dir($migrationsDir)) {
            return [];
        }

        $applied = $this->ledger->getApplied($pluginId);
        $pending = $this->discoverPending($pluginId, $namespace, $migrationsDir, $applied);

        $executed = [];
        foreach ($pending as $fqcn) {
            $this->applyOne($pluginId, $fqcn, 'up');
            $this->ledger->recordApplied($pluginId, $fqcn);
            $executed[] = $fqcn;
            $this->logger->info('Applied plugin migration', [
                'plugin_id' => $pluginId,
                'version'   => $fqcn,
            ]);
        }
        return $executed;
    }

    /**
     * Reverse every applied migration for $pluginId, in reverse order.
     * Used by `uninstall()`. Idempotent: a plugin with no applied
     * migrations is a no-op.
     */
    public function runDown(
        string $pluginId,
        ?string $namespace,
        ?string $migrationsDir,
    ): void {
        if ($namespace === null || $migrationsDir === null) {
            $this->ledger->removeAll($pluginId);
            return;
        }

        $applied = $this->ledger->getApplied($pluginId);
        if ($applied === []) {
            return;
        }

        // Make sure the classes are loadable. Same require-once strategy
        // as discoverPending so down() can run even when no Version*.php
        // file currently exists on disk (e.g. plugin tarball stripped).
        $this->requireMigrationFiles($migrationsDir);

        // Run in reverse order — Doctrine's convention.
        $reversed = array_reverse($applied);
        foreach ($reversed as $fqcn) {
            if (!class_exists($fqcn)) {
                $this->logger->warning('Skipping down() — class missing', [
                    'plugin_id' => $pluginId,
                    'version'   => $fqcn,
                ]);
                continue;
            }
            $this->applyOne($pluginId, $fqcn, 'down');
            $this->ledger->removeApplied($pluginId, $fqcn);
        }
    }

    /**
     * @param list<string> $applied
     * @return list<string> Sorted, pending migration FQCNs.
     */
    private function discoverPending(
        string $pluginId,
        string $namespace,
        string $migrationsDir,
        array $applied,
    ): array {
        $this->requireMigrationFiles($migrationsDir);

        $entries = scandir($migrationsDir);
        if ($entries === false) {
            return [];
        }

        $pending = [];
        $ns = rtrim($namespace, '\\');
        foreach ($entries as $entry) {
            if (!str_starts_with($entry, 'Version') || !str_ends_with($entry, '.php')) {
                continue;
            }
            $className = substr($entry, 0, -4);
            $fqcn = $ns . '\\' . $className;
            if (in_array($fqcn, $applied, true)) {
                continue;
            }
            if (!class_exists($fqcn)) {
                throw new PluginMigrationException(
                    $pluginId,
                    $fqcn,
                    "Plugin '{$pluginId}' migration file '{$entry}' did not declare class '{$fqcn}'.",
                );
            }
            $pending[] = $fqcn;
        }

        sort($pending);
        return $pending;
    }

    private function requireMigrationFiles(string $migrationsDir): void
    {
        $entries = scandir($migrationsDir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if (!str_starts_with($entry, 'Version') || !str_ends_with($entry, '.php')) {
                continue;
            }
            $path = $migrationsDir . '/' . $entry;
            if (is_file($path)) {
                /** @psalm-suppress UnresolvableInclude */
                require_once $path;
            }
        }
    }

    /**
     * Instantiate and execute one migration in the given direction.
     */
    private function applyOne(string $pluginId, string $fqcn, string $direction): void
    {
        if (!class_exists($fqcn)) {
            throw new PluginMigrationException(
                $pluginId,
                $fqcn,
                "Plugin '{$pluginId}' migration class '{$fqcn}' is not loadable.",
            );
        }
        if (!is_subclass_of($fqcn, AbstractMigration::class)) {
            throw new PluginMigrationException(
                $pluginId,
                $fqcn,
                "Plugin '{$pluginId}' migration '{$fqcn}' must extend " . AbstractMigration::class,
            );
        }

        // The is_subclass_of check above guarantees AbstractMigration; the
        // two suppress comments cover (1) our project-local "no dynamic new"
        // rule and (2) Psalm's UnsafeInstantiation warning about constructor
        // signature drift in user-shipped child classes. Same pattern that
        // PluginRegistry::bootInstance() uses for PluginInterface.
        /** @psalm-suppress UnsafeInstantiation */
        $migration = new $fqcn($this->connection, $this->logger); // @phpstan-ignore piwigo.noDynamicNew
        /** @var AbstractMigration $migration */

        $schema = $this->connection->createSchemaManager()->introspectSchema();
        $transactional = $migration->isTransactional();

        if ($transactional) {
            $this->connection->beginTransaction();
        }

        try {
            if ($direction === 'up') {
                $migration->preUp($schema);
                $migration->up($schema);
                $migration->postUp($schema);
            } else {
                $migration->preDown($schema);
                $migration->down($schema);
                $migration->postDown($schema);
            }

            foreach ($migration->getSql() as $query) {
                // Doctrine\Migrations\Query\Query types getParameters() /
                // getTypes() as the loose `mixed[]`. Connection::executeStatement
                // wants `array<int<0,max>|string, mixed>` / `WrapperParameterTypeArray`.
                // Doctrine's own DbalExecutor passes these unchanged — same
                // upstream type-flux they swallow internally.
                $this->connection->executeStatement(
                    $query->getStatement(),
                    $query->getParameters(), // @phpstan-ignore argument.type
                    $query->getTypes(),      // @phpstan-ignore argument.type
                );
            }

            if ($transactional) {
                $this->connection->commit();
            }
        } catch (\Throwable $e) {
            if ($transactional && $this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw new PluginMigrationException(
                $pluginId,
                $fqcn,
                "Plugin '{$pluginId}' migration '{$fqcn}' {$direction}() failed: " . $e->getMessage(),
                $e,
            );
        }
    }
}
