<?php

declare(strict_types=1);

namespace Piwigo\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;

/**
 * Records, in the schema itself, why three id-shaped columns carry no
 * foreign key.
 *
 * The audit that produced this campaign had to work out for each of them
 * whether it was an oversight or a deliberate choice, and the answer was not
 * recoverable from the schema -- only from reading the code that reads them.
 * Anyone repeating that audit would repeat the work, and the two
 * `history_summary` columns are the ones most likely to be "fixed" by
 * mistake, because they look exactly like an unconstrained reference.
 *
 * `history_summary.history_id_from`/`_to` are **watermarks, not references**.
 * `HistoryRepository::findLastSummaryWithHistoryIdTo()` is autopurge's
 * cursor: it takes the highest non-null `history_id_to` and deletes history
 * rows below it. Constraining them breaks that in either direction --
 * `SET NULL` nulls exactly the summaries the purge just consumed and stalls
 * the cursor; `CASCADE` deletes the aggregates the feature exists to
 * preserve. A summary is *supposed* to outlive the rows it summarises.
 *
 * `activity.object_id`'s comment also stops being accurate once the typed
 * reference columns exist beside it: it is no longer the way to reach the
 * referenced row, it is the durable record of which row it was.
 */
final class Version20260815240000 extends AbstractMigration
{
    /**
     * table, column, MySQL column definition (comment excluded), comment.
     *
     * The definitions are restated because MySQL's `MODIFY` replaces the
     * whole thing; PostgreSQL sets the comment on its own.
     *
     * @var list<array{string, string, string, string}>
     */
    private const array COMMENTS = [
        [
            'history_summary',
            'history_id_from',
            'int DEFAULT NULL',
            'lowest history.id folded into this summary row -- a watermark, not a reference: '
            . 'autopurge deliberately deletes the history rows this range covers, so no foreign key can exist here',
        ],
        [
            'history_summary',
            'history_id_to',
            'int DEFAULT NULL',
            'highest history.id folded into this summary row, the next run resumes past this id -- '
            . 'a watermark, not a reference, and autopurge cursors on it, so it must survive the purge it drives',
        ],
        [
            'activity',
            'object_id',
            'int NOT NULL',
            'id of the affected object, or the target user id on a logout action -- the durable record of '
            . 'which row this was about, kept when the typed reference column beside it is nulled by a deletion',
        ],
    ];

    #[Override]
    public function getDescription(): string
    {
        return 'Record in the schema why the history_summary watermarks and activity.object_id carry no foreign key';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        $isPostgres = $this->platform instanceof PostgreSQLPlatform;

        foreach (self::COMMENTS as [$table, $column, $definition, $comment]) {
            $this->addSql($isPostgres
                ? sprintf(
                    'COMMENT ON COLUMN %s.%s IS %s',
                    $table,
                    $column,
                    $this->connection->quote($comment),
                )
                : sprintf(
                    'ALTER TABLE `%s` MODIFY `%s` %s COMMENT %s',
                    $table,
                    $column,
                    $definition,
                    $this->connection->quote($comment),
                ));
        }
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Reverting would restore comments that describe these columns as plain ids, which is what made the '
            . 'missing-foreign-key audit ambiguous in the first place.'
        );
    }
}
