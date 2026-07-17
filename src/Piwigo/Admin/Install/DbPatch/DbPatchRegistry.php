<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

/**
 * Ordered id => class map of every ported one-shot database patch,
 * replacing the former opendir()/'^(.*?)-database\.php$' filename scan of
 * install/db/ (P23 sub-batch 8g). Keys are the historical ledger ids and
 * MUST stay in natcasesort order -- UpgradeService::getAvailableUpgradeIds()
 * and the version-upgrade range loops rely on it, and the registry
 * integrity test asserts it.
 *
 * TRANSITION NOTE (8g-1..8g-5): patches not yet ported (101-181) are
 * still discovered and executed from their install/db/*.php files;
 * getAvailableUpgradeIds() unions this registry with the directory scan
 * and UpgradeFeedRunner prefers a registry class when one exists. The
 * scan, the include fallback, and the files themselves all go away when
 * the port completes (8g-6).
 */
final class DbPatchRegistry
{
    /**
     * @var array<string, class-string<DbPatchInterface>>
     */
    private const array PATCHES = [
        '61' => Patch61::class,
        '62' => Patch62::class,
        '63' => Patch63::class,
        '64' => Patch64::class,
        '65' => Patch65::class,
        '66' => Patch66::class,
        '67' => Patch67::class,
        '68' => Patch68::class,
        '69' => Patch69::class,
        '70' => Patch70::class,
        '71' => Patch71::class,
        '72' => Patch72::class,
        '73' => Patch73::class,
        '74' => Patch74::class,
        '75' => Patch75::class,
        '76' => Patch76::class,
        '77' => Patch77::class,
        '78' => Patch78::class,
        '79' => Patch79::class,
        '80' => Patch80::class,
        '81' => Patch81::class,
        '82' => Patch82::class,
        '83' => Patch83::class,
        '84' => Patch84::class,
        '85' => Patch85::class,
        '86' => Patch86::class,
        '87' => Patch87::class,
        '88' => Patch88::class,
        '89' => Patch89::class,
        '90' => Patch90::class,
        '91' => Patch91::class,
        '92' => Patch92::class,
        '93' => Patch93::class,
        '94' => Patch94::class,
        '95' => Patch95::class,
        '96' => Patch96::class,
        '97' => Patch97::class,
        '98' => Patch98::class,
        '99' => Patch99::class,
        '100' => Patch100::class,
    ];

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::PATCHES);
    }

    public static function has(string $id): bool
    {
        return isset(self::PATCHES[$id]);
    }

    public static function make(string $id): DbPatchInterface
    {
        $class = self::PATCHES[$id] ?? throw new \InvalidArgumentException('Unknown db patch id: ' . $id);

        return new $class();
    }
}
