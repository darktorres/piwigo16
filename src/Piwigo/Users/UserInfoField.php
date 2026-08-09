<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Users\Projection\DqlPropertyFlag;

/**
 * {@see UserRepository::updateInfosForUsers()}'s `$updates` map keys,
 * enumerated against all 3 real callers (`UserService`'s own admin
 * bulk-update path, `ProfileFormHandler`, `ProfileController`'s
 * language-cookie sync): `level`/`language`/`theme`/`nb_image_page`/
 * `recent_period`/`expand`/`show_nb_comments`/`show_nb_hits`/
 * `enabled_high`, all real `user_infos` columns ({@see UserInfoEntity}),
 * never raw user input as a key (only the corresponding value is
 * caller-supplied).
 *
 * {@see dqlPropertyAndIsBoolean()} maps each field to its real DQL
 * property path against `UserInfoEntity`, plus whether the column is
 * boolean-typed: `expand`/`show_nb_comments`/`show_nb_hits`/
 * `enabled_high` are `bool`, the rest aren't. A raw int `1` or numeric
 * string `'1'` bound via DQL against a `boolean`-typed field writes and
 * reads back correctly (DBAL's own `AbstractPlatform::convertBooleans()`
 * passes a non-bool value straight through unchanged rather than
 * erroring, and MySQL's `tinyint(1)` column accepts it either way), but
 * callers still explicitly cast to a real PHP `bool` before binding,
 * matching the column's own declared type rather than relying on that
 * pass-through behavior.
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
