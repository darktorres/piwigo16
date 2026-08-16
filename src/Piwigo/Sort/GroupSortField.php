<?php

declare(strict_types=1);

namespace Piwigo\Sort;

/**
 * The typed column vocabulary backing `pwg.groups.getList`'s `order` param
 * (`GroupsMethodRegistrar`'s own registration info: "id, name, nb_users,
 * is_default"). Same hard-error-on-unrecognized-token contract as
 * {@see UserSortField}'s own docblock explains, applied here too for
 * consistency even though this method has no pre-existing test covering
 * the malformed case.
 *
 * No case-folding: nothing in `GroupRepository::findWithMemberCounts()`'s
 * own `name` filter (`LOWER(g.name) LIKE :nameLike`) claims a
 * case-insensitive *sort*, only a case-insensitive filter -- a different
 * concern.
 */
enum GroupSortField
{
    case Id;
    case Name;
    case NbUsers;
    case IsDefault;

    public static function fromToken(string $token): ?self
    {
        return match ($token) {
            'id' => self::Id,
            'name' => self::Name,
            'nb_users' => self::NbUsers,
            'is_default' => self::IsDefault,
            default => null,
        };
    }

    /**
     * `NbUsers` is the `COUNT(ug.user_id) AS nb_users` select alias
     * `findWithMemberCounts()` already builds -- ordering by a select
     * alias, not a `g.`-prefixed column, same as any other aggregate sort.
     */
    public function column(): string
    {
        return match ($this) {
            self::Id => 'g.id',
            self::Name => 'g.name',
            self::NbUsers => 'nb_users',
            self::IsDefault => 'g.is_default',
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
