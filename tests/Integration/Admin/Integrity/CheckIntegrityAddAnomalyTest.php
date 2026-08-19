<?php

declare(strict_types=1);

use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomalyEntity;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Lang\Translator;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;

// addAnomaly()/getHtlmLinksMoreInfo() are CheckIntegrity's own pure
// data-structure logic -- no event/template dependency at all -- but
// CheckIntegrity's constructor takes a real IntegrityIgnoredAnomalyRepository
// dependency, so constructing one at all needs a real EntityManager/DB
// connection, even though neither method under test here ever touches it.

// This file never boots Kernel (no DB-backed collaborator under test
// actually needs it), so resolving the real container-shared Lang
// instance isn't an option (there is no memoized pre-boot fallback) --
// a throwaway instance is built directly instead.
function checkIntegrityAddAnomalyTestLang(): Lang
{
    return new Lang(new Translator(new CurrentConfig(), new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))), HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag());
}

function checkIntegrityAddAnomalyNew(): CheckIntegrity
{
    $repo = EntityManagerFactory::build(DbConnection::build())->getRepository(IntegrityIgnoredAnomalyEntity::class);

    return new CheckIntegrity(checkIntegrityAddAnomalyTestLang(), $repo, new Translator(CurrentConfigTestFactory::get(), new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))), EventDispatcherTestFactory::get(), PageStateTestFactory::get(), CurrentTemplateTestFactory::get());
}

test('addAnomaly records a new anomaly with is_callable computed from a real function name', function (): void {
    $c13y = checkIntegrityAddAnomalyNew();

    $c13y->addAnomaly('Something is wrong', 'strlen', [
        'arg' => 'x',
    ], 'fix it');

    expect($c13y->retrieve_list)
        ->toHaveCount(1);
    $entry = $c13y->retrieve_list[0];
    expect($entry->anomaly)
        ->toBe('Something is wrong');
    expect($entry->correctionFct)
        ->toBe('strlen');
    expect($entry->correctionFctArgs)
        ->toBe([
            'arg' => 'x',
        ]);
    expect($entry->correctionMsg)
        ->toBe('fix it');
    expect($entry->isCallable)
        ->toBeTrue();
    expect($entry->id)
        ->toBe(md5('Something is wrongstrlen' . serialize([
            'arg' => 'x',
        ]) . 'fix it'));
});

test('addAnomaly marks is_callable false for a non-existent function name', function (): void {
    $c13y = checkIntegrityAddAnomalyNew();

    $c13y->addAnomaly('Bad correction fn', 'this_function_does_not_exist_anywhere');

    expect($c13y->retrieve_list[0]->isCallable)->toBeFalse();
});

test('addAnomaly with no correction function is never callable and carries a null correction_fct', function (): void {
    $c13y = checkIntegrityAddAnomalyNew();

    $c13y->addAnomaly('Plain anomaly, no fix available');

    $entry = $c13y->retrieve_list[0];
    expect($entry->correctionFct)
        ->toBeNull();
    expect($entry->correctionFctArgs)
        ->toBeNull();
    expect($entry->isCallable)
        ->toBeFalse();
});

test('addAnomaly routes an already-ignored id into build_ignore_list instead of retrieve_list', function (): void {
    $c13y = checkIntegrityAddAnomalyNew();
    $anomalyId = md5('Ignored anomaly' . serialize(null) . '');
    $c13y->ignore_list = [$anomalyId];

    $c13y->addAnomaly('Ignored anomaly');

    expect($c13y->retrieve_list)
        ->toBe([]);
    expect($c13y->build_ignore_list)
        ->toBe([$anomalyId]);
});

test('addAnomaly generates distinct ids for anomalies that differ only by correction_fct_args', function (): void {
    $c13y = checkIntegrityAddAnomalyNew();

    $c13y->addAnomaly('Same message', 'strlen', [
        'a' => 1,
    ]);
    $c13y->addAnomaly('Same message', 'strlen', [
        'a' => 2,
    ]);

    expect($c13y->retrieve_list)
        ->toHaveCount(2);
    expect($c13y->retrieve_list[0]->id)->not->toBe($c13y->retrieve_list[1]->id);
});

// getHtlmLinksMoreInfo() is this class's other genuinely pure method --
// AdminUiHelper::pwgUrl() is a fixed, DB/config-free constant map, and
// Lang::t()/Translator self-initialize without any Lang::load() call
// (untranslated gettext falls back to the literal English string).
test('getHtlmLinksMoreInfo formats a forum + wiki link pair from the fixed pwg URL map', function (): void {
    $c13y = checkIntegrityAddAnomalyNew();

    $result = $c13y->getHtlmLinksMoreInfo();

    expect($result)
        ->toBe(sprintf(
            'Go to %s or %s for more informations',
            '<a href="' . AppInfo::URL . '/forum" onclick="window.open(this.href, \'\'); return false;">the forum</a>',
            '<a href="' . AppInfo::URL . '/doc" onclick="window.open(this.href, \'\'); return false;">the wiki</a>'
        ));
});
