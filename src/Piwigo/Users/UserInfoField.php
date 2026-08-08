<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Users\Projection\DqlPropertyFlag;

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
 * Item 16I: converted to real DQL via {@see dqlPropertyAndIsBoolean()} --
 * the original "`expand`/`show_nb_comments`/`show_nb_hits`/`enabled_high`
 * are `UserInfoEntity`-typed as strict `bool`, but real caller-supplied
 * values are `int`/`string` `'1'`/`'0'`, forcing those through DQL's
 * `boolean` Doctrine Type conversion is a real, untested behavior
 * change" reasoning didn't hold up under direct empirical verification:
 * a raw int `1` or numeric string `'1'` bound via DQL against a
 * `boolean`-typed field writes and reads back correctly (live-probed
 * against this exact entity/column before converting) -- DBAL's own
 * `AbstractPlatform::convertBooleans()` passes a non-bool value straight
 * through unchanged rather than erroring, and MySQL's `tinyint(1)`
 * column accepts it either way. Explicitly cast to a real PHP `bool`
 * before binding regardless, matching the column's own declared type
 * rather than relying on that pass-through behavior.
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

    /**
     * [DQL property path against UserInfoEntity, whether the column is boolean-typed]
     */
    public function dqlPropertyAndIsBoolean(): DqlPropertyFlag
    {
        return match ($this) {
            self::Level => new DqlPropertyFlag('level', false),
            self::Language => new DqlPropertyFlag('language', false),
            self::Theme => new DqlPropertyFlag('theme', false),
            self::NbImagePage => new DqlPropertyFlag('nbImagePage', false),
            self::RecentPeriod => new DqlPropertyFlag('recentPeriod', false),
            self::Expand => new DqlPropertyFlag('expand', true),
            self::ShowNbComments => new DqlPropertyFlag('showNbComments', true),
            self::ShowNbHits => new DqlPropertyFlag('showNbHits', true),
            self::EnabledHigh => new DqlPropertyFlag('enabledHigh', true),
        };
    }
}
