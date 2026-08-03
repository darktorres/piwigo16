<?php

declare(strict_types=1);

namespace Piwigo\Users;

/**
 * Item 15 Sub-item D: {@see UserRepository::updateInfosForUsers()}'s
 * `$updates` map keys, enumerated -- re-derived fresh against all 3 real
 * callers (`UserService`'s own admin bulk-update path, `ProfileFormHandler`,
 * `ProfileController`'s language-cookie sync), wider than a first guess:
 * `level`/`language`/`theme`/`nb_image_page`/`recent_period`/`expand`/
 * `show_nb_comments`/`show_nb_hits`/`enabled_high`, all real `user_infos`
 * columns ({@see UserInfoEntity}), never raw user input as a key (only the
 * corresponding value is caller-supplied).
 *
 * `updateInfosForUsers()` stays on DBAL raw SQL rather than DQL despite
 * `user_infos` being mapped: `expand`/`show_nb_comments`/`show_nb_hits`/
 * `enabled_high` are `UserInfoEntity`-typed as strict `bool`, but their
 * real caller-supplied values are `int`/`string` `'1'`/`'0'`
 * ({@see \Piwigo\Db\SqlDialect::booleanToInt()}/its own
 * `ProfileFormHandler`-side string-coercion) -- forcing those through
 * DQL's `boolean` Doctrine Type conversion is a real, untested behavior
 * change for a permission-adjacent bulk write, not worth taking on just
 * to also close this column-name gap. This enum still closes the actual
 * gap (an arbitrary runtime string -> a bounded, validated set) without
 * that added risk.
 */
enum UserInfoField
{
    case Level;
    case Language;
    case Theme;
    case NbImagePage;
    case RecentPeriod;
    case Expand;
    case ShowNbComments;
    case ShowNbHits;
    case EnabledHigh;

    public static function fromToken(string $token): ?self
    {
        return match ($token) {
            'level' => self::Level,
            'language' => self::Language,
            'theme' => self::Theme,
            'nb_image_page' => self::NbImagePage,
            'recent_period' => self::RecentPeriod,
            'expand' => self::Expand,
            'show_nb_comments' => self::ShowNbComments,
            'show_nb_hits' => self::ShowNbHits,
            'enabled_high' => self::EnabledHigh,
            default => null,
        };
    }
}
