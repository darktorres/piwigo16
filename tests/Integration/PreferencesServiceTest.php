<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Tests\Support\CurrentUserTestFactory;
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
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->service = new PreferencesService(new UserRepository(EntityManagerFactory::build($this->conn), EventDispatcherTestFactory::get(), $currentConfig), CurrentUserTestFactory::get());

        CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 1, 'preferences' => []]));
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('UPDATE ' . 'user_infos' . ' SET preferences = NULL WHERE user_id = 1');
        parent::tearDown();
    }

    public function test_update_param_then_get_param_round_trips(): void
    {
        $this->service->updateParam('theme', 'dark');

        self::assertSame('dark', $this->service->getParam('theme'));

        self::assertSame('dark', CurrentUserTestFactory::get()->get()->preferences['theme']);
    }

    public function test_update_param_persists_to_the_database(): void
    {
        $this->service->updateParam('language', 'fr_FR');

        $value = $this->conn->createQueryBuilder()
            ->select('preferences')
            ->from('user_infos')
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertIsString($value);
        self::assertSame(['language' => 'fr_FR'], json_decode($value, true));
    }

    public function test_update_param_converts_the_string_true_and_false_to_bool(): void
    {
        $this->service->updateParam('flag_a', 'true');
        $this->service->updateParam('flag_b', 'false');

        self::assertTrue($this->service->getParam('flag_a'));
        self::assertFalse($this->service->getParam('flag_b'));
    }

    public function test_delete_param_removes_a_single_key(): void
    {
        $this->service->updateParam('a', 1);
        $this->service->updateParam('b', 2);

        $this->service->deleteParam('a');

        self::assertNull($this->service->getParam('a'));
        self::assertSame(2, $this->service->getParam('b'));
    }

    public function test_delete_param_accepts_a_list_of_keys(): void
    {
        $this->service->updateParam('a', 1);
        $this->service->updateParam('b', 2);
        $this->service->updateParam('c', 3);

        $this->service->deleteParam(['a', 'b']);

        self::assertNull($this->service->getParam('a'));
        self::assertNull($this->service->getParam('b'));
        self::assertSame(3, $this->service->getParam('c'));
    }

    public function test_get_param_returns_the_default_when_unset(): void
    {
        self::assertSame('fallback', $this->service->getParam('never-set', 'fallback'));
    }

    public function test_delete_param_with_an_empty_array_is_a_noop(): void
    {
        $this->service->updateParam('a', 1);

        $this->service->deleteParam([]);

        self::assertSame(1, $this->service->getParam('a'));
    }

    public function test_get_admin_theme_pref_returns_null_when_unset(): void
    {
        self::assertNull($this->service->getAdminThemePref());
    }

    public function test_get_admin_theme_pref_returns_the_stored_string(): void
    {
        $this->service->updateParam('admin_theme', 'clear');

        self::assertSame('clear', $this->service->getAdminThemePref());
    }

    public function test_get_admin_theme_pref_narrows_a_non_string_value_to_null(): void
    {
        $this->service->updateParam('admin_theme', ['not', 'scalar']);

        self::assertNull($this->service->getAdminThemePref());
    }

    public function test_get_user_manager_view_returns_null_when_unset(): void
    {
        self::assertNull($this->service->getUserManagerView());
    }

    public function test_get_user_manager_view_returns_the_stored_string(): void
    {
        $this->service->updateParam('user-manager-view', 'thumbnails');

        self::assertSame('thumbnails', $this->service->getUserManagerView());
    }

    public function test_get_user_manager_pagination_returns_null_when_unset(): void
    {
        self::assertNull($this->service->getUserManagerPagination());
    }

    public function test_get_user_manager_pagination_returns_the_stored_value_as_int(): void
    {
        $this->service->updateParam('user-manager-pagination', 20);

        self::assertSame(20, $this->service->getUserManagerPagination());
    }

    public function test_get_user_manager_pagination_narrows_a_non_numeric_value_to_null(): void
    {
        $this->service->updateParam('user-manager-pagination', 'not-a-number');

        self::assertNull($this->service->getUserManagerPagination());
    }

    public function test_get_plugin_manager_view_returns_null_when_unset(): void
    {
        self::assertNull($this->service->getPluginManagerView());
    }

    public function test_get_plugin_manager_view_returns_the_stored_string(): void
    {
        $this->service->updateParam('plugin-manager-view', 'thumbnails');

        self::assertSame('thumbnails', $this->service->getPluginManagerView());
    }

    public function test_get_promote_mobile_apps_returns_null_when_unset(): void
    {
        self::assertNull($this->service->getPromoteMobileApps());
    }

    public function test_get_promote_mobile_apps_returns_false_when_explicitly_stored_false(): void
    {
        // Explicitly false must surface as false, not be treated as
        // "absent" and silently replaced by a caller's `?? true` default.
        $this->service->updateParam('promote-mobile-apps', false);

        self::assertFalse($this->service->getPromoteMobileApps());
    }

    public function test_get_promote_mobile_apps_casts_a_truthy_value_to_true(): void
    {
        $this->service->updateParam('promote-mobile-apps', 1);

        self::assertTrue($this->service->getPromoteMobileApps());
    }

    public function test_get_show_newsletter_subscription_returns_null_when_unset(): void
    {
        self::assertNull($this->service->getShowNewsletterSubscription());
    }

    public function test_get_show_newsletter_subscription_returns_false_when_explicitly_stored_false(): void
    {
        $this->service->updateParam('show_newsletter_subscription', false);

        self::assertFalse($this->service->getShowNewsletterSubscription());
    }

    public function test_get_gallery_search_filters_returns_null_when_unset(): void
    {
        self::assertNull($this->service->getGallerySearchFilters());
    }

    public function test_get_gallery_search_filters_returns_the_stored_array(): void
    {
        $this->service->updateParam('gallery_search_filters', ['tags', 'allwords']);

        self::assertSame(['tags', 'allwords'], $this->service->getGallerySearchFilters());
    }

    public function test_get_gallery_search_filters_narrows_a_non_array_value_to_null(): void
    {
        $this->service->updateParam('gallery_search_filters', 'not-an-array');

        self::assertNull($this->service->getGallerySearchFilters());
    }
}
