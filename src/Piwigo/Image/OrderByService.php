<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * Build SQL ORDER BY clauses from structured (field, dir) tuples and parse
 * the admin form's `'field DIR'` strings back into that structure.
 *
 * Replaces the legacy practice of storing the literal SQL clause in
 * `piwigo_config.value` and splicing it verbatim into queries. The Config
 * keys `order_by`, `order_by_custom`, `order_by_inside_category` and
 * `order_by_inside_category_custom` now hold `list<array{field, dir}>`
 * (JSON-serialised); this service is the single point that knows how to
 * translate that to SQL and which fields are allowed.
 */
final class OrderByService
{
    /**
     * Image columns admins may order on. Anything outside this list is
     * rejected by parseFormToken().
     */
    public const array ALLOWED_FIELDS = [
        'file',
        'name',
        'date_creation',
        'date_available',
        'rating_score',
        'hit',
        'id',
        'rank',
    ];

    /**
     * Build a complete `ORDER BY …` SQL clause from the structured config
     * value. Returns an empty string when the input is empty (caller
     * should treat that as "no ordering").
     *
     * @param list<array{field: string, dir: string}> $orders
     */
    public function buildOrderByClause(array $orders): string
    {
        $bare = $this->buildBareOrderByClause($orders);
        return $bare === '' ? '' : 'ORDER BY ' . $bare;
    }

    /**
     * Build the ORDER BY body without the leading `ORDER BY` keyword.
     * Useful for callers that prepend their own keyword (a few places
     * concatenate the clause inside a larger SELECT and want bare columns).
     *
     * @param list<array{field: string, dir: string}> $orders
     */
    public function buildBareOrderByClause(array $orders): string
    {
        $parts = [];
        foreach ($orders as $entry) {
            $field = $entry['field'];
            $dir   = strtoupper($entry['dir']);
            if (!in_array($field, self::ALLOWED_FIELDS, true)) {
                continue;
            }
            if ($dir !== 'ASC' && $dir !== 'DESC') {
                continue;
            }
            // `rank` is a MySQL 8 reserved word — backtick it.
            $sqlField = $field === 'rank' ? '`rank`' : $field;
            $parts[]  = $sqlField . ' ' . $dir;
        }
        return implode(', ', $parts);
    }

    /**
     * Parse one admin-form token (e.g. `'date_available DESC'` or
     * `` '`rank` ASC' ``) into the structured tuple. Returns null when the
     * token doesn't match the `<field> <ASC|DESC>` shape or names a field
     * that isn't in {@see self::ALLOWED_FIELDS}.
     *
     * @return array{field: string, dir: string}|null
     */
    public function parseFormToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        if (!preg_match('/^(`?[a-z_]+`?)\s+(ASC|DESC)$/i', $token, $m)) {
            return null;
        }
        $field = trim($m[1], '`');
        $dir   = strtoupper($m[2]);
        if (!in_array($field, self::ALLOWED_FIELDS, true)) {
            return null;
        }
        return ['field' => $field, 'dir' => $dir];
    }

    /**
     * Re-emit a structured tuple in the admin form's `'field DIR'` shape
     * (preserving the historical backtick on the `rank` field for the
     * <select> option-value compatibility). Used by the admin Configuration
     * controller when rendering the current selection.
     *
     * @param array{field: string, dir: string} $entry
     */
    public function toFormToken(array $entry): string
    {
        $field = $entry['field'];
        $dir   = $entry['dir'];
        $renderedField = $field === 'rank' ? '`rank`' : $field;
        return $renderedField . ' ' . $dir;
    }

    /**
     * Build a stable cache-key fragment representing the order. Used by
     * SectionInitializer's all_iids cache. Order matters; this is a
     * deterministic serialisation of the structured value.
     *
     * @param list<array{field: string, dir: string}> $orders
     */
    public function toCacheKey(array $orders): string
    {
        return $this->buildBareOrderByClause($orders);
    }
}
