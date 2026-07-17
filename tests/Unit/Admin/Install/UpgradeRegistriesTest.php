<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\Install\DbPatch\DbPatchInterface;
use Piwigo\Admin\Install\DbPatch\DbPatchRegistry;
use Piwigo\Admin\Install\VersionUpgrade\VersionUpgradeInterface;
use Piwigo\Admin\Install\VersionUpgrade\VersionUpgradeRegistry;

// P23 sub-batch 8g-6: the two registries replaced the opendir()/regex scan
// of install/db/ and the upgrade_X.Y.Z.php include ladder. These tests pin
// the invariants the historical mechanisms guaranteed structurally.

test('DbPatchRegistry carries exactly the 121 upstream ids 61..181 as strings, in natcasesort order', function (): void {
    $ids = DbPatchRegistry::ids();

    // PHP silently casts numeric-string array keys to int; ids() must
    // re-stringify (ledger rows and array_diff against DB-fetched strings
    // depend on it). toBe() is identity, so it also pins the string type.
    expect($ids)->toBe(array_map(strval(...), range(61, 181)));

    $sorted = $ids;
    natcasesort($sorted);
    expect(array_values($sorted))->toBe($ids);
});

test('every DbPatch resolves, round-trips its id, and has a ledger description', function (): void {
    foreach (DbPatchRegistry::ids() as $id) {
        $patch = DbPatchRegistry::make($id);
        expect($patch)->toBeInstanceOf(DbPatchInterface::class)
            ->and($patch->id())->toBe($id);
        // Patch75/76/78 build their description from the runtime table
        // prefix and Patch125 accumulates during apply() -- but every
        // description must be non-empty even before apply().
        expect($patch->description())->not->toBe('');
    }
});

test('VersionUpgradeRegistry carries the full 1.3.0..15.0.0 chain and every step round-trips its release', function (): void {
    $releases = VersionUpgradeRegistry::releases();

    expect($releases)->toHaveCount(23)
        ->and($releases[0])->toBe('1.3.0')
        ->and($releases[22])->toBe('15.0.0');

    foreach ($releases as $release) {
        $step = VersionUpgradeRegistry::make($release);
        expect($step)->toBeInstanceOf(VersionUpgradeInterface::class)
            ->and($step->versionFrom())->toBe($release);
    }
});

test('unknown ids and releases are rejected', function (): void {
    expect(fn () => DbPatchRegistry::make('60'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => VersionUpgradeRegistry::make('16.0.0'))->toThrow(InvalidArgumentException::class);
});
