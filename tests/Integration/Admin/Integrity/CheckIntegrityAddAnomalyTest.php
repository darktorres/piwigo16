<?php

declare(strict_types=1);

use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomalyEntity;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomalyRepository;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Lang\Translator;

// add_anomaly()/get_htlm_links_more_info() are CheckIntegrity's own pure
// data-structure logic -- no event/template dependency at all -- but since
// the derivative_settings/derivative_size-adjacent migration gave
// CheckIntegrity a real constructor dependency (IntegrityIgnoredAnomalyRepository,
// replacing the former c13y_ignore piwigo_config blob), constructing one at
// all now needs a real EntityManager/DB connection, even though neither
// method under test here ever touches it. Relocated from
// tests/Unit/Admin/Integrity/CheckIntegrityTest.php for exactly that reason
// -- check()/display() (tests/Integration/CheckIntegrityTest.php) already
// established the DB-backed Integration precedent for this class.

function checkIntegrityAddAnomalyNew(): CheckIntegrity
{
    $repo = EntityManagerFactory::build(DbConnection::build())->getRepository(IntegrityIgnoredAnomalyEntity::class);
    expect($repo)->toBeInstanceOf(IntegrityIgnoredAnomalyRepository::class);

    return new CheckIntegrity($repo, new Translator(), \Piwigo\PluginConfig\EventDispatcher::get());
}

test('add_anomaly records a new anomaly with is_callable computed from a real function name', function (): void {
    $c13y = checkIntegrityAddAnomalyNew();

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
    $c13y = checkIntegrityAddAnomalyNew();

    $c13y->add_anomaly('Bad correction fn', 'this_function_does_not_exist_anywhere');

    expect($c13y->retrieve_list[0]['is_callable'])->toBeFalse();
});

test('add_anomaly with no correction function is never callable and carries a null correction_fct', function (): void {
    $c13y = checkIntegrityAddAnomalyNew();

    $c13y->add_anomaly('Plain anomaly, no fix available');

    $entry = $c13y->retrieve_list[0];
    expect($entry['correction_fct'])->toBeNull();
    expect($entry['correction_fct_args'])->toBeNull();
    expect($entry['is_callable'])->toBeFalse();
});

test('add_anomaly routes an already-ignored id into build_ignore_list instead of retrieve_list', function (): void {
    $c13y = checkIntegrityAddAnomalyNew();
    $anomalyId = md5('Ignored anomaly' . '' . serialize(null) . '');
    $c13y->ignore_list = [$anomalyId];

    $c13y->add_anomaly('Ignored anomaly');

    expect($c13y->retrieve_list)->toBe([]);
    expect($c13y->build_ignore_list)->toBe([$anomalyId]);
});

test('add_anomaly generates distinct ids for anomalies that differ only by correction_fct_args', function (): void {
    $c13y = checkIntegrityAddAnomalyNew();

    $c13y->add_anomaly('Same message', 'strlen', ['a' => 1]);
    $c13y->add_anomaly('Same message', 'strlen', ['a' => 2]);

    expect($c13y->retrieve_list)->toHaveCount(2);
    expect($c13y->retrieve_list[0]['id'])->not->toBe($c13y->retrieve_list[1]['id']);
});

// get_htlm_links_more_info() is this class's other genuinely pure method --
// AdminUiHelper::pwgUrl() is a fixed, DB/config-free constant map, and
// Lang::t()/Translator::get() self-initialize without any Lang::load() call
// (untranslated gettext falls back to the literal English string).
test('get_htlm_links_more_info formats a forum + wiki link pair from the fixed pwg URL map', function (): void {
    $c13y = checkIntegrityAddAnomalyNew();

    $result = $c13y->get_htlm_links_more_info();

    expect($result)->toBe(sprintf(
        'Go to %s or %s for more informations',
        '<a href="' . \Piwigo\Core\AppInfo::URL . '/forum" onclick="window.open(this.href, \'\'); return false;">the forum</a>',
        '<a href="' . \Piwigo\Core\AppInfo::URL . '/doc" onclick="window.open(this.href, \'\'); return false;">the wiki</a>'
    ));
});
