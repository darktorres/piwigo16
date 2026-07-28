<?php

declare(strict_types=1);

use Piwigo\Admin\Integrity\CheckIntegrity;

// check()/display() both need a real DI-bootstrapped CurrentConfigService/
// PageState/Translator/Template plus the 'list_check_integrity' event
// (registered only when a caller -- IntroSubController -- explicitly wires
// C13yInternal::registerHandlers() first), so those two stay Browser/
// Integration territory (already incidentally exercised by every real
// admin-dashboard visit in IntroSubControllerTest.php). add_anomaly() is
// this class's own pure data-structure logic -- no event/DB/template
// dependency at all -- and is directly, deterministically testable here.

function check_integrity_new(): CheckIntegrity
{
    return new CheckIntegrity();
}

test('add_anomaly records a new anomaly with is_callable computed from a real function name', function (): void {
    $c13y = check_integrity_new();

    $c13y->add_anomaly('Something is wrong', 'strlen', ['arg' => 'x'], 'fix it');

    expect($c13y->retrieve_list)->toHaveCount(1);
    $entry = $c13y->retrieve_list[0];
    expect($entry['anomaly'])->toBe('Something is wrong');
    expect($entry['correction_fct'])->toBe('strlen');
    expect($entry['correction_fct_args'])->toBe(['arg' => 'x']);
    expect($entry['correction_msg'])->toBe('fix it');
    expect($entry['is_callable'])->toBeTrue();
    expect($entry['id'])->toBe(md5('Something is wrong' . 'strlen' . serialize(['arg' => 'x']) . 'fix it'));
});

test('add_anomaly marks is_callable false for a non-existent function name', function (): void {
    $c13y = check_integrity_new();

    $c13y->add_anomaly('Bad correction fn', 'this_function_does_not_exist_anywhere');

    expect($c13y->retrieve_list[0]['is_callable'])->toBeFalse();
});

test('add_anomaly with no correction function is never callable and carries a null correction_fct', function (): void {
    $c13y = check_integrity_new();

    $c13y->add_anomaly('Plain anomaly, no fix available');

    $entry = $c13y->retrieve_list[0];
    expect($entry['correction_fct'])->toBeNull();
    expect($entry['correction_fct_args'])->toBeNull();
    expect($entry['is_callable'])->toBeFalse();
});

test('add_anomaly routes an already-ignored id into build_ignore_list instead of retrieve_list', function (): void {
    $c13y = check_integrity_new();
    $anomalyId = md5('Ignored anomaly' . '' . serialize(null) . '');
    $c13y->ignore_list = [$anomalyId];

    $c13y->add_anomaly('Ignored anomaly');

    expect($c13y->retrieve_list)->toBe([]);
    expect($c13y->build_ignore_list)->toBe([$anomalyId]);
});

test('add_anomaly generates distinct ids for anomalies that differ only by correction_fct_args', function (): void {
    $c13y = check_integrity_new();

    $c13y->add_anomaly('Same message', 'strlen', ['a' => 1]);
    $c13y->add_anomaly('Same message', 'strlen', ['a' => 2]);

    expect($c13y->retrieve_list)->toHaveCount(2);
    expect($c13y->retrieve_list[0]['id'])->not->toBe($c13y->retrieve_list[1]['id']);
});
