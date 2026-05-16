<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Piwigo\Admin\AdminMenuGroup;
use Piwigo\Admin\AdminPage;
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
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Core admin-page registration.
 *
 * Mirrors every entry in the nine `public const array PAGES` arrays
 * still living on the admin sub-controllers; B17 deletes those
 * constants once nothing reads them. Until then they remain as the
 * single source of truth — this subscriber walks them at boot time
 * so a future controller-PR adding a `PAGES` entry is automatically
 * picked up without editing this file.
 *
 * The menu-group assignment matches the legacy sidebar layout:
 *  - Album, Photo, BatchManager → photos/albums
 *  - Users, Groups              → users
 *  - Configuration              → configuration
 *  - Extensions                 → plugins (covers themes/languages/updates)
 *  - Maintenance                → tools
 *  - Misc                       → misc (notification, tags, comments, …)
 */
final readonly class AdminPagesRegisteringSubscriber implements EventSubscriberInterface
{
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [AdminPagesRegistering::class => 'onAdminPagesRegistering'];
    }

    public function onAdminPagesRegistering(AdminPagesRegistering $event): void
    {
        $registry = $event->registry;

        foreach (AlbumController::PAGES as $slug) {
            $registry->register(new AdminPage(
                slug: $slug,
                label: 'admin.menu.album.' . $slug,
                controllerClass: AlbumController::class,
                menuGroup: AdminMenuGroup::Albums,
                permission: AccessLevel::Administrator,
            ));
        }
        foreach (PhotoController::PAGES as $slug) {
            $registry->register(new AdminPage(
                slug: $slug,
                label: 'admin.menu.photo.' . $slug,
                controllerClass: PhotoController::class,
                menuGroup: AdminMenuGroup::Photos,
                permission: AccessLevel::Administrator,
            ));
        }
        foreach (BatchManagerController::PAGES as $slug) {
            $registry->register(new AdminPage(
                slug: $slug,
                label: 'admin.menu.batch.' . $slug,
                controllerClass: BatchManagerController::class,
                menuGroup: AdminMenuGroup::Photos,
                permission: AccessLevel::Administrator,
            ));
        }
        foreach (ConfigurationController::PAGES as $slug) {
            $registry->register(new AdminPage(
                slug: $slug,
                label: 'admin.menu.configuration.' . $slug,
                controllerClass: ConfigurationController::class,
                menuGroup: AdminMenuGroup::Configuration,
                permission: AccessLevel::Webmaster,
            ));
        }
        foreach (UsersController::PAGES as $slug) {
            $registry->register(new AdminPage(
                slug: $slug,
                label: 'admin.menu.users.' . $slug,
                controllerClass: UsersController::class,
                menuGroup: AdminMenuGroup::Users,
                permission: AccessLevel::Administrator,
            ));
        }
        foreach (GroupsController::PAGES as $slug) {
            $registry->register(new AdminPage(
                slug: $slug,
                label: 'admin.menu.groups.' . $slug,
                controllerClass: GroupsController::class,
                menuGroup: AdminMenuGroup::Users,
                permission: AccessLevel::Administrator,
            ));
        }
        foreach (ExtensionsController::PAGES as $slug) {
            $registry->register(new AdminPage(
                slug: $slug,
                label: 'admin.menu.extensions.' . $slug,
                controllerClass: ExtensionsController::class,
                menuGroup: $this->extensionsGroupFor($slug),
                permission: AccessLevel::Webmaster,
            ));
        }
        foreach (MaintenanceController::PAGES as $slug) {
            $registry->register(new AdminPage(
                slug: $slug,
                label: 'admin.menu.maintenance.' . $slug,
                controllerClass: MaintenanceController::class,
                menuGroup: AdminMenuGroup::Tools,
                permission: AccessLevel::Webmaster,
            ));
        }
        foreach (MiscController::PAGES as $slug) {
            $registry->register(new AdminPage(
                slug: $slug,
                label: 'admin.menu.misc.' . $slug,
                controllerClass: MiscController::class,
                menuGroup: AdminMenuGroup::Misc,
                permission: AccessLevel::Administrator,
            ));
        }
    }

    /**
     * Extensions controller serves multiple sidebar buckets — split the
     * registration into themes / plugins so the UI can group them.
     */
    private function extensionsGroupFor(string $slug): AdminMenuGroup
    {
        if (str_starts_with($slug, 'theme')) {
            return AdminMenuGroup::Themes;
        }
        return AdminMenuGroup::Plugins;
    }
}
