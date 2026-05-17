<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Users;

use PHPUnit\Framework\TestCase;
use Piwigo\Users\PreferencesService;

/**
 * Targets the read-path fix for the user_infos.preferences column: the
 * serialized blob produced by {@see PreferencesService::userprefsSave()}
 * must round-trip back through {@see PreferencesService::decodePreferences()}
 * so per-user prefs (admin_theme, search filters, etc.) survive across
 * requests. Pre-fix, `UserService::getuserdata` clobbered the loaded
 * column with [] and all preferences silently reverted to defaults on
 * every page load.
 */
final class PreferencesServiceTest extends TestCase
{
    public function testDecodeReturnsEmptyArrayForNull(): void
    {
        self::assertSame([], PreferencesService::decodePreferences(null));
    }

    public function testDecodeReturnsEmptyArrayForEmptyString(): void
    {
        self::assertSame([], PreferencesService::decodePreferences(''));
    }

    public function testDecodeReturnsEmptyArrayForNonString(): void
    {
        self::assertSame([], PreferencesService::decodePreferences(['already' => 'array']));
        self::assertSame([], PreferencesService::decodePreferences(42));
        self::assertSame([], PreferencesService::decodePreferences(false));
    }

    public function testDecodeRoundTripsSerializedSaveValue(): void
    {
        $prefs = [
            'admin_theme'           => 'classic',
            'plugin-manager-view'   => 'list',
            'gallery_search_filters' => ['tags', 'date_creation'],
            'promote-mobile-apps'   => false,
        ];
        $raw = serialize($prefs);

        self::assertSame($prefs, PreferencesService::decodePreferences($raw));
    }

    public function testDecodeRejectsNonArrayPayload(): void
    {
        // A serialized scalar — unlikely but defensible against malformed
        // legacy rows.
        self::assertSame([], PreferencesService::decodePreferences(serialize('a string')));
    }

    public function testDecodeFiltersOutNonStringKeys(): void
    {
        // serialize(['a' => 1, 0 => 'x']) yields a mixed-key array on
        // decode. We only want string keys (that's how the prefs map is
        // accessed: userprefsGetParam(string $param, …)).
        $raw = serialize(['admin_theme' => 'dark', 0 => 'numeric-key-junk']);
        self::assertSame(['admin_theme' => 'dark'], PreferencesService::decodePreferences($raw));
    }

    public function testDecodeIsResilientToMalformedSerializedPayload(): void
    {
        self::assertSame([], PreferencesService::decodePreferences('this is not a serialize() blob'));
    }
}
