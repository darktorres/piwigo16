<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\User;
use Piwigo\Users\UserRepository;

final class PreferencesServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private PreferencesService $service;

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->service = new PreferencesService(new UserRepository(EntityManagerFactory::build($this->conn), EventDispatcherTestFactory::get(), $currentConfig), CurrentUserTestFactory::get());

        CurrentUserTestFactory::get()->set(User::fromUserArray([
            'id' => 1,
            'preferences' => [],
        ]));
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('UPDATE user_infos SET preferences = NULL WHERE user_id = 1');
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testUpdateParamThenGetParamRoundTrips(): void
    {
        $this->service->updateParam('theme', 'dark');

        self::assertSame('dark', $this->service->getParam('theme'));

        self::assertSame('dark', CurrentUserTestFactory::get()->get()->preferences['theme']);
    }

    public function testUpdateParamPersistsToTheDatabase(): void
    {
        $this->service->updateParam('language', 'fr_FR');

        $value = $this->conn->createQueryBuilder()
            ->select('preferences')
            ->from('user_infos')
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertIsString($value);
        self::assertSame([
            'language' => 'fr_FR',
        ], json_decode($value, true));
    }

    public function testUpdateParamConvertsTheStringTrueAndFalseToBool(): void
    {
        $this->service->updateParam('flag_a', 'true');
        $this->service->updateParam('flag_b', 'false');

        self::assertTrue($this->service->getParam('flag_a'));
        self::assertFalse($this->service->getParam('flag_b'));
    }

    public function testDeleteParamRemovesASingleKey(): void
    {
        $this->service->updateParam('a', 1);
        $this->service->updateParam('b', 2);

        $this->service->deleteParam('a');

        self::assertNull($this->service->getParam('a'));
        self::assertSame(2, $this->service->getParam('b'));
    }

    public function testDeleteParamAcceptsAListOfKeys(): void
    {
        $this->service->updateParam('a', 1);
        $this->service->updateParam('b', 2);
        $this->service->updateParam('c', 3);

        $this->service->deleteParam(['a', 'b']);

        self::assertNull($this->service->getParam('a'));
        self::assertNull($this->service->getParam('b'));
        self::assertSame(3, $this->service->getParam('c'));
    }

    public function testGetParamReturnsTheDefaultWhenUnset(): void
    {
        self::assertSame('fallback', $this->service->getParam('never-set', 'fallback'));
    }

    public function testDeleteParamWithAnEmptyArrayIsANoop(): void
    {
        $this->service->updateParam('a', 1);

        $this->service->deleteParam([]);

        self::assertSame(1, $this->service->getParam('a'));
    }

    public function testGetAdminThemePrefReturnsNullWhenUnset(): void
    {
        self::assertNull($this->service->getAdminThemePref());
    }

    public function testGetAdminThemePrefReturnsTheStoredString(): void
    {
        $this->service->updateParam('admin_theme', 'clear');

        self::assertSame('clear', $this->service->getAdminThemePref());
    }

    public function testGetAdminThemePrefNarrowsANonStringValueToNull(): void
    {
        $this->service->updateParam('admin_theme', ['not', 'scalar']);

        self::assertNull($this->service->getAdminThemePref());
    }

    public function testGetUserManagerViewReturnsNullWhenUnset(): void
    {
        self::assertNull($this->service->getUserManagerView());
    }

    public function testGetUserManagerViewReturnsTheStoredString(): void
    {
        $this->service->updateParam('user-manager-view', 'thumbnails');

        self::assertSame('thumbnails', $this->service->getUserManagerView());
    }

    public function testGetUserManagerPaginationReturnsNullWhenUnset(): void
    {
        self::assertNull($this->service->getUserManagerPagination());
    }

    public function testGetUserManagerPaginationReturnsTheStoredValueAsInt(): void
    {
        $this->service->updateParam('user-manager-pagination', 20);

        self::assertSame(20, $this->service->getUserManagerPagination());
    }

    public function testGetUserManagerPaginationNarrowsANonNumericValueToNull(): void
    {
        $this->service->updateParam('user-manager-pagination', 'not-a-number');

        self::assertNull($this->service->getUserManagerPagination());
    }

    public function testGetPluginManagerViewReturnsNullWhenUnset(): void
    {
        self::assertNull($this->service->getPluginManagerView());
    }

    public function testGetPluginManagerViewReturnsTheStoredString(): void
    {
        $this->service->updateParam('plugin-manager-view', 'thumbnails');

        self::assertSame('thumbnails', $this->service->getPluginManagerView());
    }

    public function testGetPromoteMobileAppsReturnsNullWhenUnset(): void
    {
        self::assertNull($this->service->getPromoteMobileApps());
    }

    public function testGetPromoteMobileAppsReturnsFalseWhenExplicitlyStoredFalse(): void
    {
        // Explicitly false must surface as false, not be treated as
        // "absent" and silently replaced by a caller's `?? true` default.
        $this->service->updateParam('promote-mobile-apps', false);

        self::assertFalse($this->service->getPromoteMobileApps());
    }

    public function testGetPromoteMobileAppsCastsATruthyValueToTrue(): void
    {
        $this->service->updateParam('promote-mobile-apps', 1);

        self::assertTrue($this->service->getPromoteMobileApps());
    }

    public function testGetShowNewsletterSubscriptionReturnsNullWhenUnset(): void
    {
        self::assertNull($this->service->getShowNewsletterSubscription());
    }

    public function testGetShowNewsletterSubscriptionReturnsFalseWhenExplicitlyStoredFalse(): void
    {
        $this->service->updateParam('show_newsletter_subscription', false);

        self::assertFalse($this->service->getShowNewsletterSubscription());
    }

    public function testGetGallerySearchFiltersReturnsNullWhenUnset(): void
    {
        self::assertNull($this->service->getGallerySearchFilters());
    }

    public function testGetGallerySearchFiltersReturnsTheStoredArray(): void
    {
        $this->service->updateParam('gallery_search_filters', ['tags', 'allwords']);

        self::assertSame(['tags', 'allwords'], $this->service->getGallerySearchFilters());
    }

    public function testGetGallerySearchFiltersNarrowsANonArrayValueToNull(): void
    {
        $this->service->updateParam('gallery_search_filters', 'not-an-array');

        self::assertNull($this->service->getGallerySearchFilters());
    }
}
