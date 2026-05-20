<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Common\Enum\SortOrder;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Validation\InputValidator;

/**
 * Build SQL ORDER BY clauses from structured {@see OrderSpec} entries
 * and parse the admin form's `'field DIR'` strings back into that
 * structure.
 *
 * Replaces the legacy practice of storing the literal SQL clause in
 * `piwigo_config.value` and splicing it verbatim into queries. The Config
 * keys `order_by`, `order_by_custom`, `order_by_inside_category` and
 * `order_by_inside_category_custom` now hold `list<OrderSpec>`
 * (JSON-serialised at rest); this service is the single point that knows
 * how to translate that to SQL and which fields are allowed.
 */
final class OrderByService
{
    public function __construct(
        private readonly InputValidator $inputValidator,
    ) {
    }

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
     * @param list<OrderSpec> $orders
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
     * @param list<OrderSpec> $orders
     */
    public function buildBareOrderByClause(array $orders): string
    {
        $parts = [];
        foreach ($orders as $entry) {
            if (!in_array($entry->field, self::ALLOWED_FIELDS, true)) {
                continue;
            }
            // `rank` is a MySQL 8 reserved word — backtick it.
            $sqlField = $entry->field === 'rank' ? '`rank`' : $entry->field;
            $parts[]  = $sqlField . ' ' . $entry->dir->value;
        }
        return implode(', ', $parts);
    }

    /**
     * Parse one admin-form token (e.g. `'date_available DESC'` or
     * `` '`rank` ASC' ``) into the structured spec. Returns null when the
     * token doesn't match the `<field> <ASC|DESC>` shape or names a field
     * that isn't in {@see self::ALLOWED_FIELDS}.
     */
    public function parseFormToken(string $token): ?OrderSpec
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        if (!preg_match('/^(`?[a-z_]+`?)\s+(ASC|DESC)$/i', $token, $m)) {
            return null;
        }
        $field = trim($m[1], '`');
        $dir   = SortOrder::tryFrom(strtoupper($m[2]));
        if ($dir === null || !in_array($field, self::ALLOWED_FIELDS, true)) {
            return null;
        }
        return new OrderSpec($field, $dir);
    }

    /**
     * Re-emit a structured spec in the admin form's `'field DIR'` shape
     * (preserving the historical backtick on the `rank` field for the
     * <select> option-value compatibility). Used by the admin Configuration
     * controller when rendering the current selection.
     */
    public function toFormToken(OrderSpec $entry): string
    {
        $renderedField = $entry->field === 'rank' ? '`rank`' : $entry->field;
        return $renderedField . ' ' . $entry->dir->value;
    }

    /**
     * Build a stable cache-key fragment representing the order. Used by
     * SectionInitializer's all_iids cache. Order matters; this is a
     * deterministic serialisation of the structured value.
     *
     * @param list<OrderSpec> $orders
     */
    public function toCacheKey(array $orders): string
    {
        return $this->buildBareOrderByClause($orders);
    }

    /**
     * Parse the Configuration > General order_by form into the
     * structured shape persisted back into config (`order_by` and
     * `order_by_inside_category` — the latter retains the `rank`
     * field for in-album ordering, the former strips it).
     *
     * Reads and mutates `$_POST['order_by']` directly (the downstream
     * `findAllParams()` loop in ConfigurationController iterates `$_POST`
     * to update config rows; preserving the array-literal shape there
     * keeps the persisted JSON layout stable).
     *
     * @param array<string, string> $sortFields The admin-form sort-field
     *     <option> map (key = `'field DIR'` token, value = label).
     */
    public function normalizeFromPost(array $sortFields): void
    {
        if (!isset($_POST['order_by']) || $_POST['order_by'] === '') {
            PageState::current()->addError(Lang::t('No order field selected'));
            return;
        }

        $this->inputValidator->check('order_by', $_POST, true, '/^(' . implode('|', array_keys($sortFields)) . ')$/');
        $postOrderBy = is_array($_POST['order_by']) ? $_POST['order_by'] : [];

        /** @var list<OrderSpec> $parsed */
        $parsed = [];
        $seen   = [];
        foreach ($postOrderBy as $val) {
            if (!is_string($val) || $val === '') {
                continue;
            }
            $entry = $this->parseFormToken($val);
            if ($entry === null) {
                continue;
            }
            $key = $entry->field . ' ' . $entry->dir->value;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $parsed[]   = $entry;
        }

        if ($parsed === []) {
            PageState::current()->addError(Lang::t('No order field selected'));
            return;
        }

        $sliced = array_slice($parsed, 0, (int) ceil(count($sortFields) / 2));
        // `order_by` is the gallery-wide (non-category) ordering — strip `rank`
        // since manual rank only applies inside a category context.
        $orderBy = array_values(array_filter($sliced, static fn (OrderSpec $e): bool => $e->field !== 'rank'));
        if ($orderBy === []) {
            $orderBy = [new OrderSpec('id', SortOrder::Asc)];
        }
        // Persist as legacy array shape so JSON round-trips and other
        // consumers reading raw config still see the dictionary form.
        $_POST['order_by'] = array_map(
            static fn (OrderSpec $e): array => ['field' => $e->field, 'dir' => $e->dir->value],
            $orderBy
        );
        $_POST['order_by_inside_category'] = array_map(
            static fn (OrderSpec $e): array => ['field' => $e->field, 'dir' => $e->dir->value],
            $sliced
        );
    }
}
