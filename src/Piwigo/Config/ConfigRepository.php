<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityRepository;
use Piwigo\Config\Projection\ConfigParamValue;
use Piwigo\Config\Projection\ConfigValueUpdate;
use Piwigo\Db\BatchWriter;

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
     * Stays on DBAL -- bulk multi-row UPDATE via BatchWriter, not a
     * DQL-vs-DBAL question at all (ORM persist()/flush() writes one row
     * per entity, not a bulk statement).
     *
     * @param list<ConfigValueUpdate> $updates
     */
    public function massUpdateValues(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        new BatchWriter($this->getEntityManager()->getConnection())
            ->massUpdate(
                'config',
                [
                    'primary' => ['param'],
                    'update' => ['value'],
                ],
                array_map(static fn (ConfigValueUpdate $update): array => $update->toArray(), $updates)
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
     * @return list<ConfigParamValue>
     */
    public function findParamsAndValuesLike(string $likePattern): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.param', 'c.value')
            ->where('c.param LIKE :likePattern')
            ->setParameter('likePattern', $likePattern)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $result[] = new ConfigParamValue(
                param: is_string($row['param'] ?? null) ? $row['param'] : '',
                value: is_string($row['value'] ?? null) ? $row['value'] : null,
            );
        }

        return $result;
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
     * depending on timing) -- a plain, portable `INSERT` via
     * `Connection::insert()`, not `persist()`/`flush()`: a caught
     * {@see \Doctrine\DBAL\Exception\UniqueConstraintViolationException}
     * from a failed `flush()` leaves the EntityManager permanently
     * closed (`Doctrine\ORM\UnitOfWork::commit()`'s own `finally` branch
     * calls `$em->close()` on any failure, and `clear()` cannot undo
     * that), which would break every other repository sharing this
     * request's EntityManager. Plain DBAL `insert()` never touches the
     * ORM's unit of work, so a caught duplicate here has no such blast
     * radius.
     *
     * "Raw" means bypassing ConfigService's typed encode()/hydrate()
     * layer, not bypassing JSON encoding outright: `value` is a real
     * JSON column (see ConfigEntry's own docblock), so $value is
     * json_encode()'d here -- transparently to this method's own
     * caller, which still deals in a plain PHP string -- rather than
     * requiring every "raw" caller to pre-encode by hand the way
     * `upsert()`'s own callers must (see e.g. Menu\MenubarRenderer).
     * This repository's only real caller, Core\UniqueExecLock, writes
     * its plain `$exec_id . '-' . time()` token straight through --
     * valid when `value` was still a plain `text` column, but genuinely
     * invalid JSON now.
     *
     * Stays on DBAL -- DQL has no INSERT support at all, and this
     * method's whole reason to exist is the atomic insert-if-absent
     * guarantee that only bypassing the ORM provides.
     */
    public function insertIgnoreRawValue(string $param, string $value): void
    {
        $encodedValue = json_encode($value);
        assert($encodedValue !== false);

        try {
            $this->getEntityManager()
                ->getConnection()
                ->insert('config', [
                    'param' => $param,
                    'value' => $encodedValue,
                ]);
        } catch (UniqueConstraintViolationException) {
            // Another process already won the race for this $param --
            // same "IGNORE" semantic the raw INSERT IGNORE this replaces
            // had.
        }
    }

    /**
     * Raw `value` for $param, bypassing the ORM identity map -- unlike
     * `find($param)?->value`, this always re-reads the DB, which
     * {@see insertIgnoreRawValue()}'s own callers rely on (re-checking
     * which process actually won the INSERT IGNORE race, possibly from a
     * different process than the one that populated this request's
     * identity map). `false` when no such row exists, matching the
     * original's own `fetchOne()` sentinel.
     *
     * json_decode()s the stored value back into the plain string
     * {@see insertIgnoreRawValue()} encodes -- the read half of that
     * same method's own JSON-column handling.
     */
    public function findRawValue(string $param): string|false
    {
        // `param` is the entity's own @ORM\Id, so at most one row can ever
        // match -- getOneOrNullResult() can't hit its NonUniqueResultException
        // here. Scalar hydration (not entity hydration) means this still
        // issues a fresh query rather than consulting the identity map, so
        // the "always re-reads the DB" contract above holds.
        $value = $this->createQueryBuilder('c')
            ->select('c.value')
            ->where('c.param = :param')
            ->setParameter('param', $param)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);

        if (! is_string($value)) {
            return false;
        }

        $decoded = json_decode($value, true);

        return is_string($decoded) ? $decoded : false;
    }

    public function countByParam(string $param): int
    {
        $value = $this->createQueryBuilder('c')
            ->select('COUNT(c.param)')
            ->where('c.param = :param')
            ->setParameter('param', $param)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }
}
