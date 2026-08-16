<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

/**
 * The closed vocabulary of sortable photo fields.
 *
 * Vocabulary only: this enum names the fields a sort order may mention and
 * the tokens they are stored and submitted as. It renders no SQL, holds no
 * connection, and has no dependencies -- which is what lets a sort order be
 * an L0 value that L1 `Config` can hold as a property. Turning a field into
 * a column, a dialect function or a DQL path is
 * {@see \Piwigo\Db\SortRenderer}'s job, because only it knows the platform.
 *
 * Two vocabularies reach this enum, and they overlap without being equal:
 *
 * - {@see fromWsToken()} -- the web-service `order` parameter, which also
 *   accepts `rand`/`random` and the legacy `date_created`/`date_posted`
 *   spellings, and has no `rank`.
 * - {@see fromConfigToken()} -- the stored `order_by`/
 *   `order_by_inside_category` fragments, whose tokens are exactly the
 *   `$sort_fields` allow-list in `Controller\Admin\
 *   ConfigurationSubController`. It has `rank` and none of the WS aliases.
 *
 * Conflating them would silently accept tokens neither real caller can
 * produce, so they stay separate.
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
     * The web-service `order` parameter's vocabulary, including the aliases
     * the legacy `stdImageSqlOrder()` allow-list carried:
     * `date_created`/`date_posted` for `date_creation`/`date_available`, and
     * `rand`/`random` for the database's random function. Returns null for
     * anything else, which callers drop.
     */
    public static function fromWsToken(string $token): ?self
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
     * The stored-config vocabulary -- the exact field slugs the admin form
     * validates `order_by`/`order_by_inside_category` entries against.
     * `rank` is valid here and nowhere else; the WS aliases are not.
     */
    public static function fromConfigToken(string $token): ?self
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
     * How this field is written in a stored `order_by` fragment and in the
     * admin form's own `"<field> <dir>"` option keys.
     *
     * A fixed literal per field, identical on every platform -- this is a
     * storage format, not an SQL identifier. `Rank`'s backticks are part of
     * that format (they are in the `$sort_fields` keys, so they are in every
     * `config` row and every `categories.image_order` value ever written by
     * the form) and are deliberately kept, so this stays a pure read of
     * existing data rather than a migration.
     *
     * That distinction is the whole point of the split. Deriving these
     * tokens from the platform's real quoting -- as the fused enum did --
     * emitted `"rank" ASC` on PostgreSQL, which matches no `$sort_fields`
     * key, so the admin form silently pre-selected nothing for a
     * manually-ordered album there.
     *
     * `Random` has no `$sort_fields` entry (the form offers no random
     * option); its token is the literal the fragment parser recognises, so a
     * random order still round-trips through storage.
     */
    public function configToken(): string
    {
        return match ($this) {
            self::Id => 'id',
            self::File => 'file',
            self::Name => 'name',
            self::Hit => 'hit',
            self::RatingScore => 'rating_score',
            self::DateCreation => 'date_creation',
            self::DateAvailable => 'date_available',
            self::Random => 'RAND()',
            self::Rank => '`rank`',
        };
    }
}
