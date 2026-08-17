<?php

declare(strict_types=1);

namespace Piwigo\Sort;

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
     * `UserRepository::findList()`'s own query joins `user_infos AS
     * ui` unconditionally, so `Level`'s `ui.` prefix carries no
     * conditional-join risk.
     */
    public function column(): string
    {
        return match ($this) {
            self::Id => 'u.id',
            self::Username => 'LOWER(u.username)',
            self::Level => 'ui.level',
            self::Email => 'u.mail_address',
        };
    }

    /**
     * Parses a WS `order` param (`"field dir, field dir"`, direction
     * optional per entry, defaulting to `ASC`) into a real `ORDER BY` body
     * string built from {@see column()}'s own trusted column names --
     * never the caller's raw text. Returns null on anything that doesn't
     * cleanly match the fixed 4-field vocabulary, the caller's signal to
     * return a hard 1003 error rather than fall back to any default.
     */
    public static function parseOrderClause(string $order): ?string
    {
        if (trim($order) === '') {
            return null;
        }

        $columns = [];
        foreach (explode(',', $order) as $rawEntry) {
            if (preg_match('/^\s*([a-z_]+)(?:\s+(asc|desc))?\s*$/i', $rawEntry, $matches) !== 1) {
                return null;
            }

            $field = self::fromToken(strtolower($matches[1]));
            if (! $field instanceof self) {
                return null;
            }

            $dir = isset($matches[2]) && strtoupper($matches[2]) === 'DESC' ? 'DESC' : 'ASC';
            $columns[] = $field->column() . ' ' . $dir;
        }

        return implode(', ', $columns);
    }
}
