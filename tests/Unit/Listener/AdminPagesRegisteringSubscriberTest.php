<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Listener;

use PHPUnit\Framework\TestCase;
use Piwigo\Admin\AdminMenuGroup;
use Piwigo\Admin\AdminPageRegistry;
use Piwigo\Controller\Admin\AlbumController;
use Piwigo\Controller\Admin\BatchManagerController;
use Piwigo\Controller\Admin\ConfigurationController;
use Piwigo\Controller\Admin\ExtensionsController;
use Piwigo\Controller\Admin\GroupsController;
use Piwigo\Controller\Admin\MaintenanceController;
use Piwigo\Controller\Admin\MiscController;
use Piwigo\Controller\Admin\PhotoController;
use Piwigo\Controller\Admin\UsersController;
use Piwigo\Core\AccessLevel;
use Piwigo\Event\Admin\AdminPagesRegistering;
use Piwigo\Listener\AdminPagesRegisteringSubscriber;

/**
 * Asserts that walking every controller's `PAGES` array via the
 * subscriber produces a registry whose entries (count, groups,
 * controller class, permission tier) match the legacy dispatch table.
 */
final class AdminPagesRegisteringSubscriberTest extends TestCase
{
    public function testEveryControllersPagesGetRegisteredOnce(): void
    {
        $registry = new AdminPageRegistry();
        $subscriber = new AdminPagesRegisteringSubscriber();
        $subscriber->onAdminPagesRegistering(new AdminPagesRegistering($registry));

        $expected = count(AlbumController::PAGES)
            + count(PhotoController::PAGES)
            + count(BatchManagerController::PAGES)
            + count(ConfigurationController::PAGES)
            + count(UsersController::PAGES)
            + count(GroupsController::PAGES)
            + count(ExtensionsController::PAGES)
            + count(MaintenanceController::PAGES)
            + count(MiscController::PAGES);

        self::assertSame($expected, $registry->count());
    }

    public function testAlbumPagesPointAtAlbumController(): void
    {
        $registry = $this->buildRegistry();
        $page = $registry->find('album');
        self::assertNotNull($page);
        self::assertSame(AlbumController::class, $page->controllerClass);
        self::assertSame(AdminMenuGroup::Albums, $page->menuGroup);
        self::assertSame(AccessLevel::Administrator, $page->permission);
    }

    public function testBatchManagerPagesGroupUnderPhotos(): void
    {
        $registry = $this->buildRegistry();
        $page = $registry->find('batch_manager');
        self::assertNotNull($page);
        self::assertSame(AdminMenuGroup::Photos, $page->menuGroup);
        self::assertSame(BatchManagerController::class, $page->controllerClass);
    }

    public function testGroupsPagesGroupUnderUsers(): void
    {
        $registry = $this->buildRegistry();
        $page = $registry->find('group_list');
        self::assertNotNull($page);
        self::assertSame(AdminMenuGroup::Users, $page->menuGroup);
        self::assertSame(GroupsController::class, $page->controllerClass);
    }

    public function testExtensionsSplitBetweenPluginsAndThemes(): void
    {
        $registry = $this->buildRegistry();

        $plugins = $registry->find('plugins');
        self::assertNotNull($plugins);
        self::assertSame(AdminMenuGroup::Plugins, $plugins->menuGroup);
        self::assertSame(AccessLevel::Webmaster, $plugins->permission);

        $themes = $registry->find('themes');
        self::assertNotNull($themes);
        self::assertSame(AdminMenuGroup::Themes, $themes->menuGroup);
    }

    public function testMaintenanceRequiresWebmaster(): void
    {
        $registry = $this->buildRegistry();
        $page = $registry->find('maintenance');
        self::assertNotNull($page);
        self::assertSame(AdminMenuGroup::Tools, $page->menuGroup);
        self::assertSame(AccessLevel::Webmaster, $page->permission);
    }

    public function testMiscPagesGroupUnderMisc(): void
    {
        $registry = $this->buildRegistry();
        $page = $registry->find('tags');
        self::assertNotNull($page);
        self::assertSame(AdminMenuGroup::Misc, $page->menuGroup);
        self::assertSame(MiscController::class, $page->controllerClass);
    }

    private function buildRegistry(): AdminPageRegistry
    {
        $registry = new AdminPageRegistry();
        (new AdminPagesRegisteringSubscriber())
            ->onAdminPagesRegistering(new AdminPagesRegistering($registry));
        return $registry;
    }
}
