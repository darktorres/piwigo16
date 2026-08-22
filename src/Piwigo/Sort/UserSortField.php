<?php

declare(strict_types=1);

namespace Piwigo\Sort;

use Piwigo\Common\ValueObject\SortEntry;

/**
 * The typed column vocabulary backing `GET /api/v1/users`'s `order` query
 * param (id, username, level, email). Unlike {@see PhotoSortField}, an
 * unrecognized token is a hard error rather than a silently-dropped one --
 * `Controller\Api\Users\UserListController` returns a real error response
 * for a malformed `order`, and silently ignoring an unknown-but-shape-valid
 * token would just quietly bypass that instead of genuinely closing the
 * allow-list gap.
 *
 * `Username` bakes in `LOWER()` directly, replacing the handler's own
 * former `str_ireplace('username', 'LOWER(username)', $order)` -- a latent
 * bug in its own right (would have corrupted SQL for any future validated
 * token containing "username" as a substring).
 */
enum UserSortField
{
    case Id;
    case Username;
    case Level;
    case Email;

    public static function fromToken(string $token): ?self
    {
        return match ($token) {
            'id' => self::Id,
            'username' => self::Username,
            'level' => self::Level,
            'email' => self::Email,
            default => null,
        };
    }

    /**
     * `UserRepository::findList()`'s own DQL-backed query joins
     * `UserInfoEntity AS ui` unconditionally, so `Level`'s `ui.` prefix
     * carries no conditional-join risk. These are DQL property paths,
     * not raw column names -- `findList()` is DQL-backed end to end, so
     * `Email`'s own `u.mailAddress` (not the raw `mail_address` column
     * name) is what its one real caller needs.
     */
    public function column(): string
    {
        return match ($this) {
            self::Id => 'u.id',
            self::Username => 'LOWER(u.username)',
            self::Level => 'ui.level',
            self::Email => 'u.mailAddress',
        };
    }

    /**
     * Parses `GET /api/v1/users`'s `order` query param (`"field dir, field
     * dir"`, direction optional per entry, defaulting to `ASC`) into a
     * structured list of `{field, dir}` clauses, one per comma-separated
     * entry -- never the caller's raw text. `findList()` applies one
     * `addOrderBy()` call per clause rather than handing DQL's
     * `Expr\OrderBy` a single flattened, already-comma-joined string
     * (verified against `Expr\OrderBy::add()`: it treats its `$sort`
     * argument as one opaque unit, so a pre-flattened multi-field string
     * would append a spurious trailing direction). Returns null on
     * anything that doesn't cleanly match the fixed 4-field vocabulary,
     * `UserListController`'s own signal to return a 422 `problem+json`
     * error rather than fall back to any default.
     *
     * @return ?list<SortEntry<self, string>>
     */
    public static function parseOrderClause(string $order): ?array
    {
        if (trim($order) === '') {
            return null;
        }

        $clauses = [];
        foreach (explode(',', $order) as $rawEntry) {
            if (preg_match('/^\s*([a-z_]+)(?:\s+(asc|desc))?\s*$/i', $rawEntry, $matches) !== 1) {
                return null;
            }

            $field = self::fromToken(strtolower($matches[1]));
            if (! $field instanceof self) {
                return null;
            }

            $dir = isset($matches[2]) && strtoupper($matches[2]) === 'DESC' ? 'DESC' : 'ASC';
            $clauses[] = new SortEntry($field, $dir);
        }

        return $clauses;
    }
}
