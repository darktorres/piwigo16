<?php

declare(strict_types=1);

namespace Piwigo\Plugins\NbcThemeChanger;

use Piwigo\Config\Config as PiwigoConfig;
use Piwigo\Config\ConfigStorage;

/**
 * Typed Config facade for the bundled nbc_ThemeChanger plugin.
 *
 * Persists a single semicolon-separated string of selected themes. The raw
 * stored format is a string for backward compatibility; the typed accessor
 * exposes it as a list<string>.
 */
final class Config
{
    /**
     * @var array<string, array{type: string, default: mixed, method: string}>
     */
    public const SCHEMA = [
        'nbc_ThemeChanger' => [
            'type'    => 'string',
            'default' => '',
            'method'  => 'themesRaw',
        ],
    ];

    /** Raw stored value (semicolon-separated themes). */
    public static function themesRaw(): string
    {
        $conf = self::confArray();
        $raw  = $conf['nbc_ThemeChanger'] ?? '';
        return is_string($raw) ? $raw : '';
    }

    /** @return array<string, mixed> */
    private static function confArray(): array
    {
        $g = $GLOBALS['conf'] ?? [];
        return is_array($g) ? $g : [];
    }

    /** @return list<string> Selected theme names, decoded from the semicolon-separated raw form. */
    public static function themes(): array
    {
        $raw = self::themesRaw();
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(';', $raw)), static fn (string $s): bool => $s !== ''));
    }

    /** @param list<string> $themes */
    public static function setThemes(array $themes): void
    {
        $raw = implode(';', $themes);
        ConfigStorage::persist('nbc_ThemeChanger', $raw);
        PiwigoConfig::override('nbc_ThemeChanger', $raw);
    }
}
