<?php

declare(strict_types=1);

use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;

/**
 * Piwigo\Admin\Integrity\CheckIntegrity -- the "check integrity"
 * anomaly-tracking engine (register/list/correct/ignore admin
 * anomalies). Resolved via `Kernel::container()->get()` (same
 * rationale as `UpdatesSubControllerTest.php`).
 *
 * `addAnomaly()` is pure logic given a fresh instance (`ignore_list`
 * is a public, directly-settable property) -- covered for its "new
 * anomaly" and "already-ignored anomaly" branches, plus `is_callable`
 * reflecting a real callable vs a non-existent one.
 * `getHtlmLinksMoreInfo()` needs only a real `Lang::t()` call and
 * `AdminUiHelper::pwgUrl()` (a pure constant map), no DB access.
 *
 * `check()`/`updateConf()`/`maintenance()` all reach
 * `IntegrityIgnoredAnomalyRepository::syncForVersion()`, which
 * unconditionally REPLACES the real ignored-anomalies row set for the
 * current `AppInfo::VERSION` -- calling any of them here risks wiping
 * real ignored-anomaly state with no owned-fixture snapshot/restore in
 * place, too destructive for this pass and not attempted. `display()`
 * needs a real Latte template file and is not attempted either.
 */
function checkIntegrityTestSubject(): CheckIntegrity
{
    $subject = Kernel::container()->get(CheckIntegrity::class);
    if (! $subject instanceof CheckIntegrity) {
        throw new LogicException('Container returned an unexpected type for ' . CheckIntegrity::class);
    }

    return $subject;
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('addAnomaly records a new anomaly with a stable md5 id and reflects a real callable correction_fct', function (): void {
    $checkIntegrity = checkIntegrityTestSubject();

    $checkIntegrity->addAnomaly('Something is wrong', 'strtoupper', [
        'arg' => 'x',
    ], 'Fix it');

    expect($checkIntegrity->retrieve_list)
        ->toHaveCount(1);
    $entry = $checkIntegrity->retrieve_list[0];
    expect($entry['anomaly'])->toBe('Something is wrong')
        ->and($entry['correction_fct'])->toBe('strtoupper')
        ->and($entry['correction_fct_args'])->toBe([
            'arg' => 'x',
        ])
        ->and($entry['correction_msg'])->toBe('Fix it')
        ->and($entry['is_callable'])->toBeTrue()
        ->and($entry['id'])->toBe(md5('Something is wrongstrtoupper' . serialize([
            'arg' => 'x',
        ]) . 'Fix it'));
});

test('addAnomaly marks is_callable false for a non-existent correction function', function (): void {
    $checkIntegrity = checkIntegrityTestSubject();

    $checkIntegrity->addAnomaly('Something is wrong', 'not_a_real_function_xyz');

    expect($checkIntegrity->retrieve_list[0]['is_callable'])->toBeFalse();
});

test('addAnomaly diverts an already-ignored anomaly id into build_ignore_list instead of retrieve_list', function (): void {
    $checkIntegrity = checkIntegrityTestSubject();
    $alreadyIgnoredId = md5('Already known' . serialize(null) . '');
    $checkIntegrity->ignore_list = [$alreadyIgnoredId];

    $checkIntegrity->addAnomaly('Already known');

    expect($checkIntegrity->retrieve_list)
        ->toBe([])
        ->and($checkIntegrity->build_ignore_list)
        ->toBe([$alreadyIgnoredId]);
});

test('getHtlmLinksMoreInfo returns real forum/wiki links composed via AdminUiHelper::pwgUrl', function (): void {
    $checkIntegrity = checkIntegrityTestSubject();

    $result = $checkIntegrity->getHtlmLinksMoreInfo();

    expect($result)
        ->toContain('href="' . AppInfo::URL . '/forum"')
        ->and($result)
        ->toContain('href="' . AppInfo::URL . '/doc"');
});
