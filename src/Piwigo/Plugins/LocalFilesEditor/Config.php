<?php

declare(strict_types=1);

namespace Piwigo\Plugins\LocalFilesEditor;

use Piwigo\Config\Config as PiwigoConfig;
use Piwigo\Config\ConfigStorage;

/**
 * Typed Config facade for the bundled LocalFilesEditor plugin.
 *
 * Mirrors the per-plugin Config class pattern documented in CONTRIBUTING.md:
 * each plugin owns its own SCHEMA + typed accessors for the keys it persists
 * to the conf table. Reads go through the global $conf via $GLOBALS reads;
 * writes go through ConfigStorage to keep the storage backend swappable.
 *
 * Plugin code (in plugins/LocalFilesEditor/) can reference this class once
 * vendor/autoload.php is loaded (always true after Kernel::boot).
 */
final class Config
{
    /**
     * @var array<string, array{type: string, default: mixed, method: string}>
     */
    public const SCHEMA = [
        'LocalFilesEditor_tabs' => [
            'type'    => 'array',
            'default' => ['localconf', 'css', 'tpl', 'lang', 'plug'],
            'method'  => 'tabs',
        ],
    ];

    /**
     * Names of the editor tabs shown in the plugin admin UI.
     *
     * @return list<string>
     */
    public static function tabs(): array
    {
        $conf = self::confArray();
        $raw  = $conf['LocalFilesEditor_tabs'] ?? self::SCHEMA['LocalFilesEditor_tabs']['default'];
        if (!is_array($raw)) {
            return self::SCHEMA['LocalFilesEditor_tabs']['default'];
        }
        return array_values(array_map(static fn (mixed $x): string => is_scalar($x) ? (string) $x : '', $raw));
    }

    /**
     * @param list<string> $tabs
     */
    public static function setTabs(array $tabs): void
    {
        ConfigStorage::persist('LocalFilesEditor_tabs', $tabs);
        PiwigoConfig::override('LocalFilesEditor_tabs', $tabs);
    }

    /** @return array<string, mixed> */
    private static function confArray(): array
    {
        $g = $GLOBALS['conf'] ?? [];
        return is_array($g) ? $g : [];
    }
}
