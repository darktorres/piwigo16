<?php

declare(strict_types=1);

namespace Piwigo\Tests\Fixtures\Plugin\ExamplePlugin;

use Piwigo\Config\ConfigRepository;
use Piwigo\Core\Kernel;

/**
 * Reference implementation of the per-plugin Config-class pattern.
 *
 * Copy into your plugin namespace, rename PREFIX to match your plugin's
 * identifier, populate SCHEMA, and add typed accessors following the same
 * shape as Piwigo\Config\Config.
 *
 * Storage: plugin rows live in the shared config table under a dot-prefix
 * (e.g., 'example_plugin.option_one'). Accessors resolve the repository
 * through Kernel::service() — the same mechanism Config::persist() uses —
 * so no extra DI wiring is required.
 */
final class Config
{
    private const string PREFIX = 'example_plugin.';

    /** @var array<string, array{type: string, default: mixed}> */
    public const array SCHEMA = [
        'option_one' => ['type' => 'string', 'default' => ''],
        'option_two' => ['type' => 'int',    'default' => 0],
    ];

    /**
     * @return array<string, mixed>  keyed by bare param name (prefix stripped)
     */
    private static function loadAll(): array
    {
        $rows = Kernel::service(ConfigRepository::class)->findByParamPattern(self::PREFIX . '%');
        $out  = [];
        foreach ($rows as $row) {
            $out[substr($row['param'], strlen(self::PREFIX))] = $row['value'];
        }
        return $out;
    }

    public static function optionOne(): string
    {
        $all = self::loadAll();
        return isset($all['option_one']) && is_string($all['option_one'])
            ? $all['option_one']
            : self::SCHEMA['option_one']['default'];
    }

    public static function optionTwo(): int
    {
        $all = self::loadAll();
        return isset($all['option_two']) && is_int($all['option_two'])
            ? $all['option_two']
            : self::SCHEMA['option_two']['default'];
    }
}
