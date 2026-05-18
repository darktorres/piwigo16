<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;

/** Persistence layer for the config (param/value) table. */
final class ConfigRepository extends AbstractRepository
{
    /**
     * Load every config row, or rows matching the supplied WHERE fragment.
     * Caller-supplied $condition is interpolated as-is (callers are
     * application code passing static fragments) — matches the legacy
     * loadConfFromDb behavior.
     *
     * @return list<array{param: string, value: mixed}>
     */
    public function findAllRows(?string $condition = null): array
    {
        $sql = 'SELECT param, value FROM ' . $this->table('config');
        if ($condition !== null && $condition !== '') {
            $sql .= ' WHERE ' . $condition;
        }
        /** @var list<array{param: string, value: mixed}> */
        return $this->conn->executeQuery($sql)->fetchAllAssociative();
    }

    /**
     * Return the full list of config param names. Used by the admin
     * configuration form to detect which posted keys are already tracked.
     *
     * @return list<string>
     */
    public function findAllParams(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT param FROM ' . $this->table('config'),
        )->fetchFirstColumn();
        return array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $rows);
    }

    /**
     * Return (param, value) rows whose param matches the supplied LIKE
     * pattern. Used by the admin NBM configuration form to mirror all
     * "nbm_%" config rows from POST.
     *
     * @return list<array<string, mixed>>
     */
    public function findByParamPattern(string $pattern): array
    {
        return $this->conn->executeQuery(
            'SELECT param, value FROM ' . $this->table('config') . ' WHERE param LIKE ?',
            [$pattern],
            [\Doctrine\DBAL\ParameterType::STRING],
        )->fetchAllAssociative();
    }

    /** Return the stored value for $param, or null when the row is absent. */
    public function findValueByParam(string $param): ?string
    {
        $value = $this->conn->executeQuery(
            'SELECT value FROM ' . $this->table('config') . ' WHERE param = ?',
            [$param],
        )->fetchOne();
        return is_string($value) ? $value : null;
    }

    /** Upsert (INSERT … ON DUPLICATE KEY UPDATE) a single param/value pair. */
    public function upsertParamValue(string $param, string $value): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . $this->table('config') . ' (param, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
            [$param, $value, $value],
        );
    }

    /** INSERT IGNORE — used by the mutex pattern to claim a token row atomically. */
    public function insertIgnoreParamValue(string $param, string $value): void
    {
        $this->conn->executeStatement(
            'INSERT IGNORE INTO ' . $this->table('config') . ' (param, value) VALUES (?, ?)',
            [$param, $value],
        );
    }

    /** Delete the config rows with the given param names. */
    /** @param list<string> $params */
    public function deleteParams(array $params): void
    {
        if ($params === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()->delete($this->table('config'));
        $qb->where($qb->expr()->in('param', ':params'))
           ->setParameter('params', $params, ArrayParameterType::STRING);
        $qb->executeStatement();
    }

    /** True when at least one config row matches $param. */
    public function paramExists(string $param): bool
    {
        $value = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM ' . $this->table('config') . ' WHERE param = ?',
            [$param],
        )->fetchOne();
        return is_numeric($value) && (int) $value > 0;
    }
}
