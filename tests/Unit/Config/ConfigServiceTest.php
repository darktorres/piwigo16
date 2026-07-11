<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigRepository;
use Piwigo\Config\ConfigService;

// confGetParam() (P13) is pure Config::all() lookup and never touches
// $repo -- constructing a real ConfigRepository still stays Unit-tier
// since DBAL connections are lazy (no socket opens until a query
// executes) and EntityManager::getRepository() only reads ClassMetadata
// (parsed from attributes, no I/O). DB-touching methods
// (loadConfFromDb()/confUpdateParam()/confDeleteParam()/
// pwgIsDbconfWriteable()) are covered by
// tests/Integration/ConfigServiceTest.php instead.
function unconnectedConfigService(): ConfigService
{
    $connection = \Doctrine\DBAL\DriverManager::getConnection([
        'driver' => 'mysqli',
        'user' => '',
        'password' => '',
        'dbname' => '',
        'host' => 'localhost',
    ]);
    $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Piwigo'], isDevMode: true);
    $config->enableNativeLazyObjects(true);
    $em = new EntityManager($connection, $config);
    $repo = $em->getRepository(ConfigEntry::class);
    if (! $repo instanceof ConfigRepository) {
        throw new \LogicException('getRepository() returned an unexpected type.');
    }

    return new ConfigService($repo);
}

beforeEach(function (): void {
    Config::reset();
});

afterEach(function (): void {
    Config::reset();
});

test('confGetParam reads a dynamic key not in SCHEMA', function (): void {
    Config::override('blk_menubar', 'some-layout-blob');

    $service = unconnectedConfigService();

    expect($service->confGetParam('blk_menubar'))->toBe('some-layout-blob');
});

test('confGetParam falls back to the given default when the key is unset', function (): void {
    $service = unconnectedConfigService();

    expect($service->confGetParam('never_set_key', 'fallback'))->toBe('fallback')
        ->and($service->confGetParam('never_set_key'))->toBeNull();
});
