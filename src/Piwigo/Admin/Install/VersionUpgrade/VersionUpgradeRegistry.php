<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\VersionUpgrade;

/**
 * release => class map of every version-step upgrade, replacing
 * UpgradeRunner::performUpgrade()'s former
 * `include install/upgrade_<release>.php` dispatch (P23 sub-batch 8g-4).
 * The runner enters the chain at the DETECTED release; each step chains
 * forward itself, so only the entry step is looked up here.
 */
final class VersionUpgradeRegistry
{
    /**
     * @var array<string, class-string<VersionUpgradeInterface>>
     */
    private const array STEPS = [
        '1.3.0' => UpgradeFrom_1_3_0::class,
        '1.3.1' => UpgradeFrom_1_3_1::class,
        '1.4.0' => UpgradeFrom_1_4_0::class,
        '1.5.0' => UpgradeFrom_1_5_0::class,
        '1.6.0' => UpgradeFrom_1_6_0::class,
        '1.6.2' => UpgradeFrom_1_6_2::class,
        '1.7.0' => UpgradeFrom_1_7_0::class,
        '2.0.0' => UpgradeFrom_2_0_0::class,
        '2.1.0' => UpgradeFrom_2_1_0::class,
        '2.2.0' => UpgradeFrom_2_2_0::class,
        '2.3.0' => UpgradeFrom_2_3_0::class,
        '2.4.0' => UpgradeFrom_2_4_0::class,
        '2.5.0' => UpgradeFrom_2_5_0::class,
        '2.6.0' => UpgradeFrom_2_6_0::class,
        '2.7.0' => UpgradeFrom_2_7_0::class,
        '2.8.0' => UpgradeFrom_2_8_0::class,
        '2.9.0' => UpgradeFrom_2_9_0::class,
        '2.10.0' => UpgradeFrom_2_10_0::class,
        '11.0.0' => UpgradeFrom_11_0_0::class,
        '12.0.0' => UpgradeFrom_12_0_0::class,
        '13.0.0' => UpgradeFrom_13_0_0::class,
        '14.0.0' => UpgradeFrom_14_0_0::class,
        '15.0.0' => UpgradeFrom_15_0_0::class,
    ];

    /**
     * @return list<string>
     */
    public static function releases(): array
    {
        return array_keys(self::STEPS);
    }

    public static function has(string $release): bool
    {
        return isset(self::STEPS[$release]);
    }

    public static function make(string $release): VersionUpgradeInterface
    {
        $class = self::STEPS[$release] ?? throw new \InvalidArgumentException('Unknown version upgrade: ' . $release);

        return new $class();
    }
}
