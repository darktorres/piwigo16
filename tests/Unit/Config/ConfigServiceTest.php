<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Db\EntityManagerFactory;

// confGetParam() for a property-backed key reads via reflection on
// CurrentConfig's own getter and never touches $repo at all -- constructing
// a real ConfigRepository still stays Unit-tier regardless, since DBAL
// connections are lazy (no socket opens until a query executes) and
// EntityManager::getRepository() only reads ClassMetadata (parsed from
// attributes, no I/O). DB-touching methods (loadConfFromDb()/
// confUpdateParam()/confDeleteParam()/pwgIsDbconfWriteable()) are covered
// by tests/Integration/ConfigServiceTest.php instead.
function unconnectedConfigService(): ConfigService
{
    $connection = \Doctrine\DBAL\DriverManager::getConnection([
        'driver' => 'mysqli',
        'user' => '',
        'password' => '',
        'dbname' => '',
        'host' => 'localhost',
    ]);
    $repo = EntityManagerFactory::build($connection)->getRepository(ConfigEntry::class);

    return new ConfigService($repo);
}

beforeEach(function (): void {
    CurrentConfig::reset();
});

afterEach(function (): void {
    CurrentConfig::reset();
});

test('confGetParam reads a property-backed key via its own typed getter', function (): void {
    CurrentConfig::setBlkMenubar(['menu' => 50]);

    $service = unconnectedConfigService();

    expect($service->confGetParam('blk_menubar'))->toBe(['menu' => 50]);
});

// confGetParam()'s fallback behavior for a genuinely dynamic (no-property)
// key needs a real DB connection -- unlike the property-backed path above,
// it reads straight from the repository on a miss, not a pure in-memory
// lookup. Covered by tests/Integration/ConfigServiceTest.php instead.
