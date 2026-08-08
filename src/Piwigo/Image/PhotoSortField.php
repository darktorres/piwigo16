<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Db\DbCredentials;
use Piwigo\Db\SqlDialect;

/**
 * SQL-modernization audit, Item 14 Sub-phase C2: typed replacement for
 * {@see \Piwigo\Ws\WsHelper::stdImageSqlOrder()}'s own regex+switch-based
 * per-token sort-field parser -- the WS `order` param's 8 real sortable
 * tokens (confirmed by reading that method's own full body), each
 * optionally suffixed `asc`/`desc`(`ending`), comma-chained.
 *
 * Deliberately scoped to `stdImageSqlOrder()`'s own replacement, not
 * `CurrentConfig::orderBy()`/`orderByInsideCategory()`'s own stored value
 * or any of their real readers ({@see \Piwigo\Category\CategoryRepository::
 * findImageIdsForCategories()} and similar) -- re-investigated directly
 * against the current tree before designing this class (the plan's own
 * "re-run this grep before starting each sub-item" instruction) and found
 * a load-bearing precedent this sub-item must not repeat:
 * `CurrentConfig.php`'s own "nothing is frozen" gap-closure note documents
 * a prior attempt at exactly this (a typed `{field,dir}[]` shape for
 * `order_by`/`order_by_inside_category` themselves) that was reverted
 * because it "modeled NOTHING any real code ever wrote" -- every real
 * writer (`Controller\Admin\ConfigurationSubController`'s save handler)
 * always produces a raw `"ORDER BY field dir, field dir"` SQL fragment
 * string, and every real reader (15 call sites, re-confirmed via a fresh
 * grep) already treats it as trusted, caller-composed opaque text spliced
 * straight into an ORDER BY clause -- the same accepted "caller composes
 * trusted ORDER BY text" architecture this whole SQL-modernization
 * initiative already uses elsewhere (e.g.
 * {@see \Piwigo\Comment\CommentRepository::findAllWithConditions()}'s own
 * `$sortByColumn`/`$sortOrder`), not an injection risk needing binding.
 * Forcing those call sites to parse individual tokens they've never needed
 * to parse would be pure unnecessary churn with no functional or security
 * benefit. `WsHelper::stdImageSqlOrder()` is different: it's the one real
 * place that already does per-token parsing (the WS `order` param can name
 * more than one field), so it's the one place a typed parser pays for
 * itself.
 *
 * Item 16J re-audit: the "must not repeat" note above is narrower than it
 * first reads -- it rejects giving `CurrentConfig::orderBy()`'s own stored
 * value a structured type (the reverted attempt) and rejects making its
 * *callers* parse tokens they never needed to. Neither describes what
 * follows: `parseOrderByFragment()`/`dqlOrderProperty()` are an opt-in
 * translation used only inside a repository method's own DQL conversion,
 * with `CurrentConfig::orderBy()`'s stored string and every caller's
 * signature left untouched -- confirmed against
 * {@see \Piwigo\Image\ImageRepository::findIdsWithConditions()}'s own
 * docblock, which explicitly deferred "a real multi-dialect
 * `CurrentConfig::orderBy()` rewrite" to "Item 16's territory" rather than
 * closing the door on it. Also corrects a stale claim repeated in a few
 * Item 14/15G docblocks (e.g. {@see \Piwigo\Calendar\CalendarRepository::
 * findImageIds()}) that `orderByCustom()` is "admin-typed... via the admin
 * UI" -- re-verified directly against `Bootstrap\RequestBootstrap.php` and
 * `Controller\Admin\ConfigurationSubController.php`: `order_by_custom`/
 * `order_by_inside_category_custom` have no HTML form field anywhere:
 * `orderByCustom() !== null` only ever *suppresses* the validated
 * `order_by` save handler and renders a read-only "specified in your local
 * configuration file" warning. It's the sysadmin-filesystem-level
 * `$conf['order_by_custom']` override, not admin-UI-reachable text -- the
 * "genuinely open-ended, falls back to raw DBAL" case is real, but it was
 * never a live injection surface either way (still trusted, caller-typed
 * text, same as always).
 *
 * `Rank` added for this same re-audit: `` `rank` ASC `` is the 8th, ASC-only
 * entry in `ConfigurationSubController.php`'s own `$sort_fields` vocabulary
 * (confirmed via a fresh read of its real save-handler code), mapped to
 * {@see \Piwigo\Image\ImageCategoryEntity::$rank} -- a join-row property,
 * not a column on `ImageEntity` itself, so {@see dqlOrderProperty()} needs
 * an `image_category` alias to express it and returns null without one
 * (the caller's own signal to fall back to raw DBAL for that query).
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
     * Matches `stdImageSqlOrder()`'s own field-name aliases: `date_created`/
     * `date_posted` are legacy WS-facing names for `date_creation`/
     * `date_available`, and `rand`/`random` both select the DB's random
     * function. Returns null for any other token (silently dropped by the
     * caller, matching the original's own `in_array()` allowlist).
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
     * table alias (same exception `stdImageSqlOrder()`'s own $tbl_name
     * handling already carved out).
     *
     * `Rank` is quoted because `rank` is a genuine reserved word on both
     * platforms (confirmed live: a bare `SELECT rank FROM ...` fails
     * outright on MySQL, "You have an error in your SQL syntax"), but the
     * quoting character itself isn't portable -- backticks are MySQL-only,
     * and MySQL's default (non-ANSI_QUOTES) SQL mode treats a
     * double-quoted `"rank"` as a *string literal*, not an identifier
     * reference (confirmed live), so this can't be a single hardcoded
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
            self::Random => SqlDialect::randomFunction() . '()',
            self::Rank => DbCredentials::fromEnv()->driver === 'pgsql' ? '"rank"' : '`rank`',
        };
    }

    /**
     * Token match for `Controller\Admin\ConfigurationSubController.php`'s
     * own `$sort_fields` vocabulary -- the exact 8 field slugs it validates
     * `order_by`/`order_by_inside_category` entries against (re-confirmed
     * via a fresh read of its real save-handler code for this sub-item).
     * Deliberately separate from {@see fromToken()}: that one carries
     * `WsHelper::stdImageSqlOrder()`'s own legacy WS-param aliases
     * (`date_created`/`date_posted`/`rand`/`random`), none of which are
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
     * on anything that doesn't cleanly match -- callers must fall back to
     * the existing raw-DBAL path unchanged for that case (mirrors
     * `orderByCustom()`'s own always-null-here contract, since a sysadmin's
     * local-config override is never one of these 15 known entries).
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
            if (preg_match('/^\s*`?([a-z_]+)`?\s+(ASC|DESC)\s*$/i', $rawEntry, $matches) !== 1) {
                return null;
            }

            $field = self::fromSortFieldToken(strtolower($matches[1]));
            if ($field === null) {
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
     * DQL property path for this field within a query aliasing `ImageEntity`
     * as $imageAlias and (optionally) `ImageCategoryEntity` as
     * $imageCategoryAlias -- every field but `Rank` lives on the image row
     * itself; `Rank` lives on the join row ({@see ImageCategoryEntity::
     * $rank}) and returns null without an $imageCategoryAlias, the signal
     * for that call site to fall back to raw DBAL rather than silently
     * dropping the sort. `Random` has no property path (a function call,
     * not sortable via a DQL property expression) and always returns null.
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
            self::Random => null,
            self::Rank => $imageCategoryAlias === null ? null : $imageCategoryAlias . '.rank',
        };
    }

    /**
     * Shared Item 16J entry point: parses $orderBySql and maps every entry
     * to a DQL property path in one step, for a query aliasing `ImageEntity`
     * as $imageAlias and (optionally) `ImageCategoryEntity` as
     * $imageCategoryAlias. Null means "fall back to raw DBAL for this
     * call" (unparseable text, or an entry -- almost always `Rank` --
     * this particular query has no alias to express), never "no order."
     * Deliberately doesn't check `CurrentConfig::orderByCustom()`/
     * `orderByInsideCategoryCustom()` itself -- which flag applies (or
     * whether the caller's $orderBySql is a plain config value at all, as
     * opposed to a dynamically-composed one like
     * {@see \Piwigo\Calendar\CalendarRenderer::render()}'s own
     * date-field-prepended fragment) is each call site's own decision.
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
