<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Piwigo\Admin\Extensions\CoreUpdateService;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigRepository;
use Piwigo\Config\ConfigService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\TablePrefixListener;
use Piwigo\Html\HtmlService;
use Piwigo\Url\UrlService;

// Only containerVersionCompare() is covered here -- checkPiwigoUpgrade()/
// getPiwigoNewVersions()/notifyPiwigoNewVersions()/upgradeTo() all talk to
// the real PHPWG_URL over the network via Piwigo\Http\HttpClientService's
// static fetch()/fetchToFile() (P23 batch 8d -- was the legacy fetchRemote()
// free function; still no injectable HTTP client seam, same limitation),
// matching the project's own documented "piwigo.org outbound-call"
// flakiness class -- not exercised here. containerVersionCompare() also
// never touches the injected ConfigService (Legacy Coupling Retirement
// Phase 5), so this only needs a type-satisfying instance, never an
// actually-queried one -- Doctrine's DBAL connection is lazy (build()
// never opens a real connection until a query runs), so constructing a
// real ConfigRepository/ConfigService here is safe without a reachable
// test DB.
function core_update_service(): CoreUpdateService
{
    $conn = DbConnection::build();
    $ormConfig = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 4) . '/src/Piwigo'], isDevMode: true);
    $ormConfig->enableNativeLazyObjects(true);
    $em = new EntityManager($conn, $ormConfig);
    $em->getEventManager()->addEventListener(Events::loadClassMetadata, new TablePrefixListener());
    $repo = $em->getRepository(ConfigEntry::class);
    if (! $repo instanceof ConfigRepository) {
        throw new \LogicException('Container returned an unexpected type for ' . ConfigRepository::class);
    }

    return new CoreUpdateService(new ZipExtractor(), new RedirectService(), new UrlService(new HtmlService()), new ConfigService($repo));
}

test('containerVersionCompare orders by semantic version first', function (): void {
    expect(core_update_service()->containerVersionCompare('16.1.0a', '16.2.0a'))->toBeLessThan(0)
        ->and(core_update_service()->containerVersionCompare('16.2.0a', '16.1.0a'))->toBeGreaterThan(0);
});

test('containerVersionCompare falls back to the container letter suffix on a semantic tie', function (): void {
    expect(core_update_service()->containerVersionCompare('16.2.0a', '16.2.0b'))->toBeTrue()
        ->and(core_update_service()->containerVersionCompare('16.2.0b', '16.2.0a'))->toBeFalse();
});

test('containerVersionCompare treats an identical version as no earlier suffix', function (): void {
    expect(core_update_service()->containerVersionCompare('16.2.0a', '16.2.0a'))->toBeFalse();
});
