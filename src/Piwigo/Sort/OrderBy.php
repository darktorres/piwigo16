<?php

declare(strict_types=1);

namespace Piwigo\Sort;

use Piwigo\Db\SqlDialect;

/**
 * A photo sort order, as a value rather than the `"ORDER BY file ASC, id
 * ASC"` string it used to be threaded around as.
 *
 * The vocabulary is closed: `Controller\Admin\ConfigurationSubController`
 * builds `order_by`/`order_by_inside_category` out of its own `$sort_fields`
 * allow-list, and `Ws\ImageSqlOrderBuilder::stdImageSqlOrder()` maps the WS `order`
 * parameter through {@see PhotoSortField}. Both used to flatten that
 * structure into text immediately, leaving every consumer to parse it back
 * -- which is why the `RAND()` portability rewrite and the platform-specific
 * `rank` quoting had to be repeated at each call site, and why "can this run
 * as DQL?" was answered by a regex rather than by asking the value.
 *
 * The one genuinely open-ended case is the sysadmin's filesystem-level
 * `$conf['order_by_custom']` override, which is not admin-UI-reachable and
 * can hold anything. {@see raw()} keeps it as opaque text and {@see isRaw()}
 * says so, so a caller can tell "an order I understand" from "an order I can
 * only splice".
 */
final readonly class OrderBy
{
    /**
     * @param list<array{field: PhotoSortField, dir: 'ASC'|'DESC'}> $entries
     */
    private function __construct(
        private array $entries,
        private ?string $raw,
    ) {}

    public static function none(): self
    {
        return new self([], null);
    }

    /**
     * The default applied when no `order_by` has been configured -- matches
     * install/config.sql's own seed row.
     */
    public static function default(): self
    {
        return self::fromConfigFragment('ORDER BY date_available DESC, file ASC, id ASC');
    }

    /**
     * Parses a stored `order_by`/`order_by_inside_category` fragment. Text
     * that doesn't match the known vocabulary is kept verbatim via
     * {@see raw()} rather than dropped -- a sysadmin override reaches this
     * same property.
     */
    public static function fromConfigFragment(string $fragment): self
    {
        if (trim($fragment) === '') {
            return self::none();
        }

        $parsed = PhotoSortField::parseOrderByFragment($fragment);

        return $parsed === null ? self::raw($fragment) : new self($parsed, null);
    }

    /**
     * The WS `order` parameter vocabulary, which unlike the config one also
     * accepts the `rand`/`random` and legacy `date_created`/`date_posted`
     * aliases. Unknown tokens are dropped, matching the original
     * `stdImageSqlOrder()` allow-list behaviour.
     */
    public static function fromWsOrderParam(string $order): self
    {
        if (trim($order) === '') {
            return self::none();
        }

        $matches = [];
        preg_match_all('/([a-z_]+) *(?:(asc|desc)(?:ending)?)? *(?:, *|$)/i', $order, $matches);

        $entries = [];
        for ($i = 0; $i < count($matches[1]); $i++) {
            $field = PhotoSortField::fromToken(strtolower($matches[1][$i]));
            if (! $field instanceof PhotoSortField) {
                continue;
            }

            $entries[] = [
                'field' => $field,
                'dir' => strtoupper($matches[2][$i]) === 'DESC' ? 'DESC' : 'ASC',
            ];
        }

        return new self($entries, null);
    }

    /**
     * An order this class can't interpret -- the sysadmin
     * `order_by_custom`/`order_by_inside_category_custom` override.
     */
    public static function raw(string $fragment): self
    {
        return new self([], $fragment);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [] && ($this->raw === null || trim($this->raw) === '');
    }

    public function isRaw(): bool
    {
        return $this->raw !== null;
    }

    /**
     * A complete `ORDER BY ...` clause, or `''` when there is nothing to
     * order by. $tableAlias prefixes every real column (never `RAND()`,
     * which is a function call, matching stdImageSqlOrder()'s own carve-out).
     *
     * Raw fragments still get the `RAND()` rewrite: PostgreSQL has no such
     * function, and this is the one place that rewrite now lives -- it used
     * to be repeated by every raw-SQL consumer, each of which had to
     * remember. A raw fragment missing its own `ORDER BY` keyword (a caller
     * that stores just the bare field list, e.g. `categories.image_order`)
     * gets one prepended -- {@see raw()}'s established caller
     * (`CurrentConfig::$orderByCustom`) always stores it pre-included, but
     * nothing enforces that for every caller, so this class adds it rather
     * than silently emitting invalid SQL for the ones that don't.
     */
    public function toSql(?string $tableAlias = null): string
    {
        if ($this->raw !== null) {
            $sql = str_ireplace('RAND()', SqlDialect::randomFunction(), $this->raw);

            if ($sql === '' || preg_match('/^\s*ORDER BY\b/i', $sql) === 1) {
                return $sql;
            }

            return 'ORDER BY ' . $sql;
        }

        if ($this->entries === []) {
            return '';
        }

        return 'ORDER BY ' . $this->toSqlBody($tableAlias);
    }

    /**
     * {@see toSql()} without the leading `ORDER BY`, for the callers that
     * store or display the bare field list (the admin form, and the WS
     * album rows that expose an `image_order`).
     */
    public function toSqlBody(?string $tableAlias = null): string
    {
        if ($this->raw !== null) {
            return trim(preg_replace('/^\s*ORDER BY\s+/i', '', $this->toSql()) ?? '');
        }

        $prefix = $tableAlias === null || $tableAlias === '' ? '' : $tableAlias . '.';

        return implode(', ', array_map(
            // Random is a function call: it takes neither a table prefix nor
            // a direction, so it renders as a bare `RAND()`.
            static fn (array $entry): string => $entry['field'] === PhotoSortField::Random
                ? $entry['field']->column()
                : $prefix . $entry['field']->column() . ' ' . $entry['dir'],
            $this->entries,
        ));
    }

    /**
     * The `$sort_fields`-shaped `"<field> <dir>"` tokens the admin
     * configuration form pre-selects from. Empty for a raw override, which
     * that form can't represent either.
     *
     * @return list<string>
     */
    public function toSortFieldTokens(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['field']->column() . ' ' . $entry['dir'],
            $this->entries,
        );
    }

    /**
     * This order as DQL clauses against a query aliasing `ImageEntity` as
     * $imageAlias and optionally `ImageCategoryEntity` as
     * $imageCategoryAlias, or null when it can't be expressed that way --
     * a raw override, or a `Rank` entry in a query with no join alias to
     * hang it on. Null means "fall back to raw SQL", never "no order".
     *
     * @return list<OrderByClause>|null
     */
    public function toDql(string $imageAlias, ?string $imageCategoryAlias = null): ?array
    {
        if ($this->raw !== null || $this->entries === []) {
            return null;
        }

        $clauses = [];
        foreach ($this->entries as $entry) {
            $property = $entry['field']->dqlOrderProperty($imageAlias, $imageCategoryAlias);
            if ($property === null) {
                return null;
            }

            $clauses[] = new OrderByClause($property, $entry['dir']);
        }

        return $clauses;
    }
}
