<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Doctrine\ORM\EntityRepository;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;

/**
 * `find()`/`findAll()` are inherited from EntityRepository for free --
 * `find()` resolves by primary key (param), which covers the only real
 * non-trivial call site found across the current codebase
 * (`load_conf_from_db('param = "..."', ...)`, a single-param filter).
 * Every other real call site loads everything (no condition).
 *
 * @extends EntityRepository<ConfigEntry>
 */
final class ConfigRepository extends EntityRepository
{
    /**
     * Insert or update a single param/value/comment row.
     */
    public function upsert(string $param, ?string $value, ?string $comment = null): void
    {
        $em = $this->getEntityManager();
        $entry = $this->find($param);

        if ($entry === null) {
            $em->persist(new ConfigEntry($param, $value, $comment));
        } else {
            $entry->value = $value;
            if ($comment !== null) {
                $entry->comment = $comment;
            }
        }

        $em->flush();
    }

    public function deleteByParam(string $param): void
    {
        $entry = $this->find($param);
        if ($entry === null) {
            return;
        }

        $em = $this->getEntityManager();
        $em->remove($entry);
        $em->flush();
    }

    /**
     * Bulk plain-UPDATE (not upsert()'s own insert-or-update semantics --
     * every row here is expected to already exist) -- Admin\Upload\
     * UploadService::saveUploadFormConfig()'s own "apply every validated
     * upload-setting field at once" step.
     *
     * @param list<array{param: string, value: mixed}> $updates
     */
    public function massUpdateValues(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        new BatchWriter($this->getEntityManager()->getConnection())
            ->massUpdate(
                Tables::config(),
                [
                    'primary' => ['param'],
                    'update' => ['value'],
                ],
                $updates
            );
    }

    /**
     * Every known param name -- Controller\Admin\ConfigurationSubController's
     * own "update every posted param that has a config row" sweep. This
     * repository's first real read method (see class docblock: every other
     * call site loads everything via the inherited findAll(), unconditionally).
     *
     * @return list<string>
     */
    public function findAllParamNames(): array
    {
        return array_map(
            static fn (ConfigEntry $entry): string => $entry->param,
            $this->findAll()
        );
    }

    /**
     * param/value pairs whose param matches $likePattern (an already-built
     * SQL LIKE pattern, e.g. `nbm\_%`) -- Controller\Admin\
     * NotificationByMailSubController's own "every nbm_* setting" sweep.
     *
     * @return list<array{param: string, value: ?string}>
     */
    public function findParamsAndValuesLike(string $likePattern): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('param', 'value')
            ->from(\Piwigo\Db\Tables::config())
            ->where('param LIKE :likePattern')
            ->setParameter('likePattern', $likePattern)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'param' => is_string($row['param']) ? $row['param'] : '',
                'value' => is_string($row['value'] ?? null) ? $row['value'] : null,
            ],
            $rows
        );
    }

    /**
     * Atomically inserts a param/value row only if it doesn't already
     * exist, silently no-opping (never throwing a duplicate-key error) if
     * another process won the race first -- Core\UniqueExecLock's own
     * distributed-lock primitive, the entire reason this class exists
     * (see its own docblock). Deliberately bypasses the ORM entirely
     * (`upsert()` above is a find-then-persist round trip with no atomic
     * "insert if absent" guarantee, and would either silently overwrite
     * the winning process's row or throw a real primary-key violation
     * depending on timing) -- raw `INSERT IGNORE`, unchanged from the
     * original SQL text.
     */
    public function insertIgnoreRawValue(string $param, string $value): void
    {
        // SQL-modernization audit: $param/$value used to be spliced
        // directly into the query text (manual '"..."' quote-wrapping,
        // no escaping) -- now bound. INSERT ... SET (rather than
        // QueryBuilder::insert()->values()) stays: this is the one
        // place in the codebase MySQL's SET-form INSERT IGNORE syntax
        // was used, and QueryBuilder has no INSERT IGNORE support at all
        // (see Db\BatchWriter::singleInsert()'s own docblock on the same
        // constraint) -- bound placeholders work identically in either
        // INSERT syntax, so the fix doesn't require picking the
        // VALUES-form instead.
        $configTable = \Piwigo\Db\Tables::config();
        $query = <<<SQL
            INSERT IGNORE
            INTO {$configTable}
            SET param = :param
                , value = :value
            SQL;

        $this->getEntityManager()
            ->getConnection()
            ->executeStatement($query, [
                'param' => $param,
                'value' => $value,
            ]);
    }

    /**
     * Raw `value` for $param, bypassing the ORM identity map -- unlike
     * `find($param)?->value`, this always re-reads the DB, which
     * {@see insertIgnoreRawValue()}'s own callers rely on (re-checking
     * which process actually won the INSERT IGNORE race, possibly from a
     * different process than the one that populated this request's
     * identity map). `false` when no such row exists, matching the
     * original's own `fetchOne()` sentinel.
     */
    public function findRawValue(string $param): string|false
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('value')
            ->from(\Piwigo\Db\Tables::config())
            ->where('param = :param')
            ->setParameter('param', $param)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : false;
    }

    public function countByParam(string $param): int
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(\Piwigo\Db\Tables::config())
            ->where('param = :param')
            ->setParameter('param', $param)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }
}
