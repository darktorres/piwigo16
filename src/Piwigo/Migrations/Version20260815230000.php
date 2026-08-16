<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;

/**
 * Replaces `activity`'s polymorphic reference with an exclusive arc, and
 * gives `audit_log` the same treatment for its one live entity type.
 *
 * **The column was doing two jobs.** Until the referenced row is deleted,
 * `activity.object_id` is a live reference *and* a statement of fact.
 * Afterwards it is only a fact. One column cannot carry both, because a
 * foreign key forces the first role -- which is why every attempt to
 * constrain a log either leaves dangling references or destroys the log.
 * Measured against real data, every delete-action row already points at
 * something gone (19 of 19 album deletes, 18 of 18 tag deletes -- by
 * definition, the row exists *because* the thing was deleted), so
 * `ON DELETE SET NULL` on the existing column would have erased precisely
 * the most useful records.
 *
 * Both roles are therefore represented:
 *
 *   typed FK column   live reference, ON DELETE SET NULL, cannot dangle
 *   object_id         historical fact, never modified, what it was about
 *
 * That is standard audit practice, and this codebase already does it on one
 * side: `GroupService` snapshots a deleted group's name into `audit_log`
 * precisely so the record outlives the row.
 *
 * **`system_scope` fixes a genuine overload.** `activity` rows with
 * `object = 'system'` do not store a row id in `object_id` at all -- they
 * store an `ActivitySystem` constant (`Core = 1`, `Plugin = 2`,
 * `Theme = 3`, see `src/Piwigo/Core/ActivitySystem.php`). One column, two
 * unrelated meanings, distinguishable only by reading the discriminator.
 * Confirmed against live rows; it is the kind of defect that never shows up
 * in a schema file.
 *
 * **There is no CHECK enforcing the arc**, though the design called for one.
 * MySQL forbids naming a column in a CHECK when that column's foreign key
 * carries a referential action, which `SET NULL` is -- so the two cannot
 * coexist. Enforcing it on PostgreSQL alone would reintroduce the silent
 * provider divergence this campaign has been removing. The foreign keys are
 * the half worth keeping: they make a dangling reference impossible, while
 * the CHECK only restated what `ActivityService::record()` already
 * guarantees by writing exactly one typed column per row. See the comment at
 * that point in up().
 *
 * **`audit_log` keeps `entity_id` for a second reason.**
 * `AuditService::computeHash()` folds it into every row's `row_hash`, so
 * changing or nulling it would invalidate the whole chain and destroy the
 * tamper-evidence of the rows being rewritten. It gains a typed column
 * alongside; the hash payload does not move.
 */
final class Version20260815230000 extends AbstractMigration
{
    /**
     * Discriminator value => [new column, referenced table].
     *
     * `system` is deliberately absent: it is not a reference at all.
     *
     * @var array<string, array{string, string}>
     */
    private const array ARC = [
        'user' => ['user_id', 'users'],
        'album' => ['category_id', 'categories'],
        'photo' => ['image_id', 'images'],
        'tag' => ['tag_id', 'tags'],
        'group' => ['group_id', 'groups'],
    ];

    #[Override]
    public function getDescription(): string
    {
        return 'Give activity typed, constrained references while keeping object_id as the historical record';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        $isPostgres = $this->platform instanceof PostgreSQLPlatform;
        $quote = $isPostgres
            ? static fn (string $name): string => '"' . $name . '"'
            : static fn (string $name): string => '`' . $name . '`';

        $this->reportOrphans();

        foreach (self::ARC as $discriminator => [$column, $refTable]) {
            $this->addSql(sprintf(
                'ALTER TABLE activity ADD COLUMN %s INT DEFAULT NULL',
                $column,
            ));

            // Only rows whose referent still exists; the rest stay null,
            // which is the correct state for a log entry about something
            // that has since been deleted.
            $this->addSql($isPostgres
                ? sprintf(
                    'UPDATE activity a SET %s = a.object_id FROM %s r WHERE r.id = a.object_id AND a.object = %s',
                    $column,
                    $quote($refTable),
                    $this->connection->quote($discriminator),
                )
                : sprintf(
                    'UPDATE activity a JOIN %s r ON r.id = a.object_id SET a.%s = a.object_id WHERE a.object = %s',
                    $quote($refTable),
                    $column,
                    $this->connection->quote($discriminator),
                ));

            if ($isPostgres) {
                // InnoDB indexes a foreign key automatically; PostgreSQL does
                // not, and enforcing the referential action without one means
                // a sequential scan of this table per parent delete (§7).
                $this->addSql(sprintf('CREATE INDEX activity_%s_idx ON activity (%s)', $column, $column));
            }

            $this->addSql(sprintf(
                'ALTER TABLE activity ADD CONSTRAINT fk_activity_%s FOREIGN KEY (%s) REFERENCES %s (id) ON DELETE SET NULL',
                $column,
                $column,
                $quote($refTable),
            ));
        }

        // The overload: system rows carry an ActivitySystem constant here,
        // never a row id.
        $this->addSql('ALTER TABLE activity ADD COLUMN system_scope SMALLINT DEFAULT NULL');
        $this->addSql("UPDATE activity SET system_scope = object_id WHERE object = 'system'");

        // No CHECK enforcing "at most one typed column is set", despite that
        // being the arc's defining rule. MySQL refuses outright:
        //
        //   Column 'category_id' cannot be used in a check constraint
        //   'chk_activity_single_reference': needed in a foreign key
        //   constraint 'fk_activity_category_id' referential action
        //
        // A column cannot be both subject to a referential action and named
        // in a CHECK. PostgreSQL permits it, so the constraint could exist
        // there alone -- but a rule enforced on one provider and not the
        // other is precisely the silent divergence §7-§10 existed to remove,
        // and it would be the one rule no local run could ever exercise
        // (.env.test is mysqli). Given the choice, the foreign keys are
        // worth more: they make a dangling reference impossible, where the
        // CHECK only asserts something ActivityService::record() already
        // guarantees by writing exactly one typed column per row.
        //
        // audit_log: one live entity type exists today ('group'). More typed
        // columns join this as more types appear; entity_type/entity_id stay
        // as the historical record the hash chain covers.
        $this->addSql('ALTER TABLE audit_log ADD COLUMN group_id INT DEFAULT NULL');
        $this->addSql($isPostgres
            ? 'UPDATE audit_log a SET group_id = a.entity_id FROM ' . $quote('groups')
                . " r WHERE r.id = a.entity_id AND a.entity_type = 'group'"
            : 'UPDATE audit_log a JOIN ' . $quote('groups')
                . " r ON r.id = a.entity_id SET a.group_id = a.entity_id WHERE a.entity_type = 'group'");

        if ($isPostgres) {
            $this->addSql('CREATE INDEX audit_log_group_id_idx ON audit_log (group_id)');
        }

        $this->addSql(
            'ALTER TABLE audit_log ADD CONSTRAINT fk_audit_log_group_id '
            . 'FOREIGN KEY (group_id) REFERENCES ' . $quote('groups') . ' (id) ON DELETE SET NULL'
        );
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Dropping the typed columns would restore a schema in which one column means five different things.'
        );
    }

    /**
     * Counts, per discriminator, how many rows reference something that no
     * longer exists.
     *
     * Reported rather than treated as an error, unlike every other
     * constraint added in this campaign: for a log these are *expected* --
     * an entry recording a deletion necessarily outlives its subject. The
     * count is worth printing because it says how much of the table will
     * carry a null typed reference and rely on `object_id` alone.
     */
    private function reportOrphans(): void
    {
        foreach (self::ARC as $discriminator => [, $refTable]) {
            $orphans = $this->connection->createQueryBuilder()
                ->select('COUNT(*)')
                ->from('activity', 'a')
                // Quoted: `groups` is a reserved word on both engines, and
                // the query builder does not quote table names itself.
                ->leftJoin('a', $this->platform->quoteSingleIdentifier($refTable), 'r', 'r.id = a.object_id')
                ->where('a.object = :discriminator')
                ->andWhere('r.id IS NULL')
                ->setParameter('discriminator', $discriminator)
                ->executeQuery()
                ->fetchOne();

            if (is_numeric($orphans) && (int) $orphans > 0) {
                $this->write(sprintf(
                    '  %s: %d row(s) reference a deleted %s -- typed column stays null, object_id keeps the id.',
                    $discriminator,
                    (int) $orphans,
                    $refTable,
                ));
            }
        }
    }
}
