<?php

declare(strict_types=1);

namespace Piwigo\Sort;

use Piwigo\Db\DbCredentials;
use Piwigo\Db\SqlDialect;

/**
 * The typed image sort-field vocabulary underlying {@see OrderBy} --
 * `fromToken()` backs `OrderBy::fromWsOrderParam()`'s per-token parse of
 * the WS `order` param (8 real sortable tokens, each optionally suffixed
 * `asc`/`desc`(`ending`), comma-chained, plus the WS-only `rand`/`random`
 * and legacy `date_created`/`date_posted` aliases, replacing the legacy WS
 * order-builder's own regex+switch parser); `fromSortFieldToken()` backs
 * `OrderBy::fromConfigFragment()`'s parse of a stored `order_by`/
 * `order_by_inside_category` fragment against
 * `ConfigurationSubController.php`'s own `$sort_fields` vocabulary. Both
 * paths converge on this one enum for column names and platform-specific
 * rendering (`Rank`'s quoting, `Random`'s dialect function) instead of
 * repeating them per call site -- see `OrderBy`'s own class docblock for
 * why the structured value replaced the raw-string convention project-wide.
 *
 * The two vocabularies overlap but are not identical, which is why they
 * are separate methods: `rand`/`random` and the legacy `date_created`/
 * `date_posted` names are WS-only, and `rank` is config-only.
 *
 * `parseOrderByFragment()`/`dqlOrderProperty()` are an opt-in translation
 * used only inside a repository method's own DQL conversion, matching
 * {@see \Piwigo\Image\ImageRepository::findIdsWithConditions()}'s own
 * docblock.
 *
 * There is no `order_by_custom`-style escape hatch in either vocabulary
 * above -- that sysadmin-filesystem-level override was never admin-UI-
 * reachable and is gone (see `OrderBy`'s own class docblock). `OrderBy::
 * raw()` still exists, but only for `categories.image_order`, a genuinely
 * open-ended, admin-settable per-category field this enum's own closed
 * vocabulary was never meant to cover.
 *
 * `Rank`: `` `rank` ASC `` is the 8th, ASC-only entry in
 * `ConfigurationSubController.php`'s own `$sort_fields` vocabulary,
 * mapped to {@see \Piwigo\Image\ImageCategoryEntity::$rank} -- a join-row
 * property, not a column on `ImageEntity` itself, so
 * {@see dqlOrderProperty()} needs an `image_category` alias to express it
 * and returns null without one. That is the only remaining reason a
 * parsed order can fail to become DQL.
 */
enum PhotoSortField
{
    case Id;
    case File;
    case Name;
    case Hit;
    case RatingScore;
    case DateCreation;
    case DateAvailable;
    case Random;
    case Rank;

    /**
     * Matches the legacy WS order-builder's own field-name aliases:
     * `date_created`/`date_posted` are legacy WS-facing names for
     * `date_creation`/`date_available`, and `rand`/`random` both select the
     * DB's random function. Returns null for any other token (silently
     * dropped by the caller, matching the original's own `in_array()`
     * allowlist).
     */
    public static function fromToken(string $token): ?self
    {
        return match ($token) {
            'id' => self::Id,
            'file' => self::File,
            'name' => self::Name,
            'hit' => self::Hit,
            'rating_score' => self::RatingScore,
            'date_creation', 'date_created' => self::DateCreation,
            'date_available', 'date_posted' => self::DateAvailable,
            'rand', 'random' => self::Random,
            default => null,
        };
    }

    /**
     * The real column/function name this field sorts on. `Random` is a
     * function call, not a column -- callers must not prefix it with a
     * table alias (same exception the legacy order-builder's own
     * $tbl_name handling already carved out).
     *
     * `Rank` is quoted because `rank` is a genuine reserved word on both
     * platforms (a bare `SELECT rank FROM ...` fails
     * outright on MySQL, "You have an error in your SQL syntax"), but the
     * quoting character itself isn't portable -- backticks are MySQL-only,
     * and MySQL's default (non-ANSI_QUOTES) SQL mode treats a
     * double-quoted `"rank"` as a *string literal*, not an identifier
     * reference, so this can't be a single hardcoded
     * literal the way most of this method's other cases are. No
     * `Connection` is available in this plain enum method (same
     * constraint {@see \Piwigo\Db\SqlDialect::randomFunction()} already
     * has), so the platform comes from `DbCredentials::fromEnv()->driver`
     * the same way.
     */
    public function column(): string
    {
        return match ($this) {
            self::Id => 'id',
            self::File => 'file',
            self::Name => 'name',
            self::Hit => 'hit',
            self::RatingScore => 'rating_score',
            self::DateCreation => 'date_creation',
            self::DateAvailable => 'date_available',
            self::Random => SqlDialect::randomFunction(),
            self::Rank => DbCredentials::fromEnv()->driver === 'pgsql' ? '"rank"' : '`rank`',
        };
    }

    /**
     * Token match for `Controller\Admin\ConfigurationSubController.php`'s
     * own `$sort_fields` vocabulary -- the exact 8 field slugs it validates
     * `order_by`/`order_by_inside_category` entries against.
     * Deliberately separate from {@see fromToken()}: that one carries
     * the legacy WS-param aliases (`date_created`/`date_posted`/`rand`/
     * `random`), none of which are
     * valid `$sort_fields` entries, and `$sort_fields` has `rank`, which
     * isn't a valid WS `order` token either -- the two vocabularies
     * overlap but aren't identical, so conflating them would silently
     * accept tokens neither real caller can actually produce.
     */
    public static function fromSortFieldToken(string $token): ?self
    {
        return match ($token) {
            'id' => self::Id,
            'file' => self::File,
            'name' => self::Name,
            'hit' => self::Hit,
            'rating_score' => self::RatingScore,
            'date_creation' => self::DateCreation,
            'date_available' => self::DateAvailable,
            'rank' => self::Rank,
            default => null,
        };
    }

    /**
     * Parses a `CurrentConfig::orderBy()`/`orderByInsideCategory()`-shaped
     * `"ORDER BY field dir, field dir"` fragment into a structured list,
     * strictly against the bounded `$sort_fields` vocabulary. Returns null
     * on anything that doesn't cleanly match -- text outside the
     * vocabulary is invalid config data, not an order to honour, and
     * {@see OrderBy::fromConfigFragment()} substitutes the default.
     *
     * @return list<array{field: self, dir: 'ASC'|'DESC'}>|null
     */
    public static function parseOrderByFragment(string $orderBySql): ?array
    {
        $body = preg_replace('/^\s*ORDER BY\s+/i', '', trim($orderBySql));
        if ($body === null || $body === '') {
            return null;
        }

        $entries = [];
        foreach (explode(',', $body) as $rawEntry) {
            // `RAND()`/`RANDOM()` is a function call with no direction, so it
            // never matches the "<field> ASC|DESC" shape below. Recognising it
            // here keeps a random order structured rather than dropping the
            // whole fragment to raw text -- which matters because the
            // MySQL/PostgreSQL spelling differs and only the structured path
            // rewrites it.
            if (preg_match('/^\s*(?:RAND|RANDOM)\s*\(\s*\)\s*$/i', $rawEntry) === 1) {
                $entries[] = [
                    'field' => self::Random,
                    'dir' => 'ASC',
                ];

                continue;
            }

            if (preg_match('/^\s*`?([a-z_]+)`?\s+(ASC|DESC)\s*$/i', $rawEntry, $matches) !== 1) {
                return null;
            }

            $field = self::fromSortFieldToken(strtolower($matches[1]));
            if (! $field instanceof \Piwigo\Sort\PhotoSortField) {
                return null;
            }

            /** @var 'ASC'|'DESC' $dir */
            $dir = strtoupper($matches[2]);
            $entries[] = [
                'field' => $field,
                'dir' => $dir,
            ];
        }

        return $entries;
    }

    /**
     * DQL order expression for this field within a query aliasing
     * `ImageEntity` as $imageAlias and (optionally) `ImageCategoryEntity`
     * as $imageCategoryAlias -- every field but `Random` and `Rank` is a
     * plain property path on the image row.
     *
     * `Random` is not a property path but a function call, and Doctrine's
     * own grammar accepts a `FunctionDeclaration` as an ORDER BY item
     * (`Query\Parser::OrderByItem()`), so the registered `RAND` custom
     * numeric function ({@see \Piwigo\Db\DqlFunction\RandFunction},
     * per-platform dispatch) expresses it -- the same `->orderBy('RAND()')`
     * {@see \Piwigo\Category\CategoryRepository::
     * findRandomRepresentativeIdAmongSubcategories()} already runs.
     *
     * `Rank` lives on the join row ({@see ImageCategoryEntity::$rank}) and
     * returns null without an $imageCategoryAlias, the signal for that call
     * site to fall back to raw DBAL rather than silently dropping the sort.
     * It is the only case that can still return null.
     */
    public function dqlOrderProperty(string $imageAlias, ?string $imageCategoryAlias = null): ?string
    {
        return match ($this) {
            self::Id => $imageAlias . '.id',
            self::File => $imageAlias . '.file',
            self::Name => $imageAlias . '.name',
            self::Hit => $imageAlias . '.hit',
            self::RatingScore => $imageAlias . '.ratingScore',
            self::DateCreation => $imageAlias . '.dateCreation',
            self::DateAvailable => $imageAlias . '.dateAvailable',
            self::Random => 'RAND()',
            self::Rank => $imageCategoryAlias === null ? null : $imageCategoryAlias . '.rank',
        };
    }

    /**
     * Parses $orderBySql and maps every entry to a DQL order expression in
     * one step, for a query aliasing `ImageEntity` as $imageAlias and
     * (optionally) `ImageCategoryEntity` as $imageCategoryAlias. Null means
     * "fall back to raw DBAL for this call" -- text outside the vocabulary,
     * or a `Rank` entry this particular query has no alias to express --
     * never "no order."
     *
     * Takes a string rather than an {@see OrderBy} because its callers hand
     * it a dynamically-composed fragment, not a plain config value: see
     * {@see \Piwigo\Calendar\CalendarRenderer::render()}, which prepends the
     * calendar's own date field ahead of the configured order.
     *
     * @return list<OrderByClause>|null
     */
    public static function resolveDqlOrderBy(string $orderBySql, string $imageAlias, ?string $imageCategoryAlias = null): ?array
    {
        $parsed = self::parseOrderByFragment($orderBySql);
        if ($parsed === null) {
            return null;
        }

        $entries = [];
        foreach ($parsed as $entry) {
            $property = $entry['field']->dqlOrderProperty($imageAlias, $imageCategoryAlias);
            if ($property === null) {
                return null;
            }

            $entries[] = new OrderByClause($property, $entry['dir']);
        }

        return $entries;
    }
}
