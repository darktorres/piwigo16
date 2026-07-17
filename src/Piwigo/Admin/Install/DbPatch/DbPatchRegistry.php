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
 * TRANSITION NOTE (8g-3..8g-5): the registry is COMPLETE (all 121
 * upstream ids, 61-181), but the install/db/*.php files stay on disk
 * until 8g-4/8g-5 port the frozen install/upgrade_X.Y.Z.php version
 * scripts that still include them by literal path (their file_exists()
 * range guards would silently skip deleted patches). The directory scan
 * in getAvailableUpgradeIds(), UpgradeFeedRunner's include fallback, and
 * the files themselves all go away in 8g-6.
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
        '101' => Patch101::class,
        '102' => Patch102::class,
        '103' => Patch103::class,
        '104' => Patch104::class,
        '105' => Patch105::class,
        '106' => Patch106::class,
        '107' => Patch107::class,
        '108' => Patch108::class,
        '109' => Patch109::class,
        '110' => Patch110::class,
        '111' => Patch111::class,
        '112' => Patch112::class,
        '113' => Patch113::class,
        '114' => Patch114::class,
        '115' => Patch115::class,
        '116' => Patch116::class,
        '117' => Patch117::class,
        '118' => Patch118::class,
        '119' => Patch119::class,
        '120' => Patch120::class,
        '121' => Patch121::class,
        '122' => Patch122::class,
        '123' => Patch123::class,
        '124' => Patch124::class,
        '125' => Patch125::class,
        '126' => Patch126::class,
        '127' => Patch127::class,
        '128' => Patch128::class,
        '129' => Patch129::class,
        '130' => Patch130::class,
        '131' => Patch131::class,
        '132' => Patch132::class,
        '133' => Patch133::class,
        '134' => Patch134::class,
        '135' => Patch135::class,
        '136' => Patch136::class,
        '137' => Patch137::class,
        '138' => Patch138::class,
        '139' => Patch139::class,
        '140' => Patch140::class,
        '141' => Patch141::class,
        '142' => Patch142::class,
        '143' => Patch143::class,
        '144' => Patch144::class,
        '145' => Patch145::class,
        '146' => Patch146::class,
        '147' => Patch147::class,
        '148' => Patch148::class,
        '149' => Patch149::class,
        '150' => Patch150::class,
        '151' => Patch151::class,
        '152' => Patch152::class,
        '153' => Patch153::class,
        '154' => Patch154::class,
        '155' => Patch155::class,
        '156' => Patch156::class,
        '157' => Patch157::class,
        '158' => Patch158::class,
        '159' => Patch159::class,
        '160' => Patch160::class,
        '161' => Patch161::class,
        '162' => Patch162::class,
        '163' => Patch163::class,
        '164' => Patch164::class,
        '165' => Patch165::class,
        '166' => Patch166::class,
        '167' => Patch167::class,
        '168' => Patch168::class,
        '169' => Patch169::class,
        '170' => Patch170::class,
        '171' => Patch171::class,
        '172' => Patch172::class,
        '173' => Patch173::class,
        '174' => Patch174::class,
        '175' => Patch175::class,
        '176' => Patch176::class,
        '177' => Patch177::class,
        '178' => Patch178::class,
        '179' => Patch179::class,
        '180' => Patch180::class,
        '181' => Patch181::class,
    ];

    /**
     * PHP silently casts numeric-string array keys to int, so the keys
     * come back re-stringified here -- callers get the exact string ids
     * the historical filename scan produced (ledger rows, array_diff
     * against DB-fetched strings, and DbPatchInterface::id() all use
     * strings).
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_map(strval(...), array_keys(self::PATCHES));
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
