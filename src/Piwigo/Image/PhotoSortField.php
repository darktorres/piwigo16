<?php

declare(strict_types=1);

namespace Piwigo\Image;

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
            self::Random => SqlDialect::DB_RANDOM_FUNCTION . '()',
        };
    }
}
