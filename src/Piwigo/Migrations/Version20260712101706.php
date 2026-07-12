<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Piwigo\Config\Config;

/**
 * `audit_log` [SEC-57] -- append-only, tamper-evident audit trail. Not
 * part of P15's own "7 new tables" inventory (see that migration's own
 * docblock): the doc's text places `AuditService` at P18, this is that
 * table landing with its consuming service, not a P15 gap.
 *
 * PII-aware: `actor_id` stores a user id, not raw PII; `before_json`/
 * `after_json` snapshots are the caller's own responsibility to keep
 * PII-minimal (AuditService itself doesn't redact -- see its own
 * docblock for which fields P18's real call sites choose to record).
 *
 * Tamper-evidence via a per-row hash chain: `row_hash` covers this row's
 * own content plus the previous row's `row_hash` (`prev_hash`, NULL only
 * for the first row ever written), so altering any row invalidates every
 * later row's hash too -- AuditService::verifyChain() walks the table and
 * recomputes it. Never UPDATEd/DELETEd (enforced at the application layer
 * by AuditRepository only exposing an insert -- no DB-level trigger,
 * matching this project's "use Doctrine Migrations, not hand-rolled
 * triggers" convention elsewhere).
 */
final class Version20260712101706 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create audit_log [SEC-57]: append-only, hash-chained audit trail';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $prefix = Config::dbPrefix();

        if ($schema->hasTable($prefix . 'audit_log')) {
            return;
        }

        $t = $schema->createTable($prefix . 'audit_log');
        $t->addOption('charset', 'utf8mb4');
        $t->addOption('collation', 'utf8mb4_unicode_ci');

        $t->addColumn('id', Types::INTEGER, [
            'notnull' => true,
            'unsigned' => true,
            'autoincrement' => true,
        ]);
        $t->addColumn('actor_id', Types::INTEGER, [
            'notnull' => false,
            'unsigned' => true,
        ]);
        if (! $this->platform instanceof PostgreSQLPlatform) {
            // users.id is mediumint(8) unsigned on MySQL/MariaDB (real
            // origin width, not DBAL's portable INTEGER) -- an FK between
            // mismatched widths is rejected as "incompatible" even though
            // both are logically the same unsigned range (same
            // empirically-found class of bug as
            // Version20260711150857's/Version20260711150903's own fixes
            // for this exact mismatch).
            $t->getColumn('actor_id')
                ->setColumnDefinition('mediumint(8) unsigned DEFAULT NULL');
        }
        $t->addColumn('action', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $t->addColumn('entity_type', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $t->addColumn('entity_id', Types::INTEGER, [
            'notnull' => false,
            'unsigned' => true,
        ]);
        $t->addColumn('before_json', Types::JSON, [
            'notnull' => false,
        ]);
        $t->addColumn('after_json', Types::JSON, [
            'notnull' => false,
        ]);
        $t->addColumn('ip_address', Types::STRING, [
            'notnull' => false,
            'length' => 45,
        ]);
        $t->addColumn('created_at', Types::DATETIME_MUTABLE, [
            'notnull' => true,
        ]);
        $t->addColumn('prev_hash', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);
        $t->addColumn('row_hash', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);

        $t->setPrimaryKey(['id']);
        $t->addIndex(['entity_type', 'entity_id'], 'idx_audit_log_entity');
        $t->addIndex(['actor_id'], 'idx_audit_log_actor');
        $t->addIndex(['created_at'], 'idx_audit_log_created_at');

        $t->addForeignKeyConstraint(
            $prefix . 'users',
            ['actor_id'],
            ['id'],
            [
                'onDelete' => 'SET NULL',
            ],
            'fk_audit_log_actor_id',
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $prefix = Config::dbPrefix();

        if ($schema->hasTable($prefix . 'audit_log')) {
            $schema->dropTable($prefix . 'audit_log');
        }
    }
}
