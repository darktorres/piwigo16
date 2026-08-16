<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Piwigo\Common\ValueObject\PhotoSortField;
use Piwigo\Common\ValueObject\PhotoSortOrder;

/**
 * Renders a {@see PhotoSortOrder} as SQL or DQL.
 *
 * The half of the former `Piwigo\Sort` namespace that needs a database:
 * column names are portable, but `rank` is a reserved word whose quoting
 * character is not (backticks are MySQL-only, and MySQL's default
 * non-ANSI_QUOTES mode reads a double-quoted `"rank"` as a *string
 * literal*, not an identifier), and the random function is `RAND()` on
 * MySQL/MariaDB and `RANDOM()` on PostgreSQL.
 *
 * Both now come from the real connection's platform --
 * `quoteSingleIdentifier()` and an `instanceof` on the platform -- rather
 * than from `DbCredentials::fromEnv()`, which the fused enum had to call on
 * every `column()` read because a plain enum method has no connection to
 * ask. That is the concrete cost the split removes: an env read on a hot
 * path, replaced by an injected dependency.
 */
final readonly class SortRenderer
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * A complete `ORDER BY ...` clause, or `''` when there is nothing to
     * order by.
     *
     * $tableAlias prefixes every real column, but never the random
     * expression -- that is a function call, not a column, the same
     * carve-out `stdImageSqlOrder()` always had.
     */
    public function toSql(PhotoSortOrder $order, ?string $tableAlias = null): string
    {
        if ($order->isEmpty()) {
            return '';
        }

        return 'ORDER BY ' . $this->toSqlBody($order, $tableAlias);
    }

    /**
     * {@see toSql()} without the leading `ORDER BY`, for callers handing the
     * body to a query builder whose own `orderBy()` prepends that keyword.
     */
    public function toSqlBody(PhotoSortOrder $order, ?string $tableAlias = null): string
    {
        $prefix = $tableAlias === null || $tableAlias === '' ? '' : $tableAlias . '.';

        return implode(', ', array_map(
            fn (array $entry): string => $entry['field'] === PhotoSortField::Random
                ? $this->randomExpression()
                : $prefix . $this->column($entry['field']) . ' ' . $entry['dir']->value,
            $order->entries(),
        ));
    }

    /**
     * This order as DQL clauses against a query aliasing `ImageEntity` as
     * $imageAlias and optionally `ImageCategoryEntity` as
     * $imageCategoryAlias, or null when it cannot be expressed that way --
     * only a `Rank` entry in a query with no join alias to hang it on. Null
     * means "fall back to raw SQL", never "no order".
     *
     * @return list<OrderByClause>|null
     */
    public function toDql(PhotoSortOrder $order, string $imageAlias, ?string $imageCategoryAlias = null): ?array
    {
        if ($order->isEmpty()) {
            return null;
        }

        $clauses = [];
        foreach ($order->entries() as $entry) {
            $property = self::dqlProperty($entry['field'], $imageAlias, $imageCategoryAlias);
            if ($property === null) {
                return null;
            }

            $clauses[] = new OrderByClause($property, $entry['dir']->value);
        }

        return $clauses;
    }

    /**
     * {@see toDql()} for a caller holding a fragment rather than a value --
     * {@see \Piwigo\Calendar\CalendarRenderer::render()} composes one by
     * prepending the calendar's own date field to the configured order, so
     * it has no {@see PhotoSortOrder} to pass.
     *
     * Null also covers text outside the vocabulary here, since a composed
     * fragment is not a plain config value and cannot fall back to the
     * default the way {@see PhotoSortOrder::fromConfigFragment()} does.
     *
     * @return list<OrderByClause>|null
     */
    public function resolveDqlOrderBy(string $orderBySql, string $imageAlias, ?string $imageCategoryAlias = null): ?array
    {
        // tryFromConfigFragment(), not fromConfigFragment(): a composed
        // fragment must not silently become the default order, which is why
        // the strict parse exists. Null drops the caller to the raw-SQL path
        // it already has.
        $order = PhotoSortOrder::tryFromConfigFragment($orderBySql);
        if (! $order instanceof PhotoSortOrder) {
            return null;
        }

        return $this->toDql($order, $imageAlias, $imageCategoryAlias);
    }

    /**
     * The real column or expression this field sorts on, quoted for the
     * connected platform.
     */
    public function column(PhotoSortField $field): string
    {
        return match ($field) {
            PhotoSortField::Id => 'id',
            PhotoSortField::File => 'file',
            PhotoSortField::Name => 'name',
            PhotoSortField::Hit => 'hit',
            PhotoSortField::RatingScore => 'rating_score',
            PhotoSortField::DateCreation => 'date_creation',
            PhotoSortField::DateAvailable => 'date_available',
            PhotoSortField::Random => $this->randomExpression(),
            PhotoSortField::Rank => $this->rankColumn(),
        };
    }

    /**
     * `rank` is a reserved word on both platforms -- a bare
     * `SELECT rank FROM ...` is a syntax error on MySQL -- so it is always
     * quoted, by the platform rather than by a hardcoded character.
     */
    public function rankColumn(): string
    {
        return $this->platform()->quoteSingleIdentifier('rank');
    }

    /**
     * The complete random-ordering expression, parens included.
     *
     * Delegates the seeding policy to {@see SqlDialect::randomFunctionFor()}
     * so there is one definition of it, but supplies the platform from the
     * real connection instead of the environment.
     */
    public function randomExpression(): string
    {
        return SqlDialect::randomFunctionFor($this->platform() instanceof PostgreSQLPlatform);
    }

    /**
     * DQL order expression for one field.
     *
     * Static and platform-free on purpose: DQL is portable by construction.
     * `rank`'s quoting is Doctrine's job (`ImageCategoryEntity` maps the
     * column as `` `rank` `` and the ORM re-quotes per platform), and
     * `Random` renders through the registered `RAND` custom function
     * ({@see DqlFunction\RandFunction}), which dispatches on the platform at
     * SQL-walk time. Doctrine's grammar accepts a FunctionDeclaration as an
     * ORDER BY item, so the function call is a valid entry.
     *
     * Null only for `Rank` without an $imageCategoryAlias: it lives on the
     * join row, so a query that never joined it has nothing to order by.
     */
    private static function dqlProperty(PhotoSortField $field, string $imageAlias, ?string $imageCategoryAlias): ?string
    {
        return match ($field) {
            PhotoSortField::Id => $imageAlias . '.id',
            PhotoSortField::File => $imageAlias . '.file',
            PhotoSortField::Name => $imageAlias . '.name',
            PhotoSortField::Hit => $imageAlias . '.hit',
            PhotoSortField::RatingScore => $imageAlias . '.ratingScore',
            PhotoSortField::DateCreation => $imageAlias . '.dateCreation',
            PhotoSortField::DateAvailable => $imageAlias . '.dateAvailable',
            PhotoSortField::Random => 'RAND()',
            PhotoSortField::Rank => $imageCategoryAlias === null ? null : $imageCategoryAlias . '.rank',
        };
    }

    private function platform(): AbstractPlatform
    {
        return $this->connection->getDatabasePlatform();
    }
}
