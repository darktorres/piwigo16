<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;

/**
 * Persistence layer for the config (param/value) table.
 *
 * The `value` column is a native JSON column. This repository is the
 * single encode/decode boundary: callers pass and receive native PHP
 * scalars/arrays/null; the JSON shape stays internal to the storage layer.
 */
final class ConfigRepository extends AbstractRepository
{
    /**
     * Load every config row, or rows matching the supplied WHERE fragment.
     * Caller-supplied $condition is interpolated as-is (callers are
     * application code passing static fragments) — matches the legacy
     * loadConfFromDb behavior. Values are JSON-decoded.
     *
     * @return list<array{param: string, value: mixed}>
     */
    public function findAllRows(?string $condition = null): array
    {
        $sql = 'SELECT param, value FROM ' . $this->table('config');
        if ($condition !== null && $condition !== '') {
            $sql .= ' WHERE ' . $condition;
        }
        $rows = $this->conn->executeQuery($sql)->fetchAllAssociative();
        $out  = [];
        foreach ($rows as $row) {
            $out[] = [
                'param' => is_string($row['param']) ? $row['param'] : '',
                'value' => self::decode($row['value'] ?? null),
            ];
        }
        return $out;
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
     * "nbm_%" config rows from POST. Values are JSON-decoded.
     *
     * @return list<array{param: string, value: mixed}>
     */
    public function findByParamPattern(string $pattern): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT param, value FROM ' . $this->table('config') . ' WHERE param LIKE ?',
            [$pattern],
            [\Doctrine\DBAL\ParameterType::STRING],
        )->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'param' => is_string($row['param']) ? $row['param'] : '',
                'value' => self::decode($row['value'] ?? null),
            ];
        }
        return $out;
    }

    /** Return the JSON-decoded value for $param, or null when the row is absent. */
    public function findValueByParam(string $param): mixed
    {
        $value = $this->conn->executeQuery(
            'SELECT value FROM ' . $this->table('config') . ' WHERE param = ?',
            [$param],
        )->fetchOne();
        if ($value === false) {
            return null;
        }
        return self::decode($value);
    }

    /** Upsert (INSERT … ON DUPLICATE KEY UPDATE) a single param/value pair. */
    public function upsertParamValue(string $param, mixed $value): void
    {
        $encoded = self::encode($value);
        $this->conn->executeStatement(
            'INSERT INTO ' . $this->table('config') . ' (param, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
            [$param, $encoded, $encoded],
        );
    }

    /** INSERT IGNORE — used by the mutex pattern to claim a token row atomically. */
    public function insertIgnoreParamValue(string $param, mixed $value): void
    {
        $this->conn->executeStatement(
            'INSERT IGNORE INTO ' . $this->table('config') . ' (param, value) VALUES (?, ?)',
            [$param, self::encode($value)],
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

    /**
     * JSON-decode a value from the DB. The column is `json DEFAULT NULL`,
     * so SQL NULL surfaces as PHP null; otherwise we get the raw JSON text.
     */
    private static function decode(mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }
        if (!is_string($raw)) {
            return $raw;
        }
        return json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * JSON-encode a value for the DB. PHP null maps to SQL NULL (DBAL handles
     * that automatically when the column type is nullable JSON).
     */
    private static function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
