<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Controller\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piwigo\Controller\Admin\AdminController;
use Piwigo\Controller\Admin\AlbumController;
use Piwigo\Controller\Admin\BatchManagerController;
use Piwigo\Controller\Admin\ConfigurationController;
use Piwigo\Controller\Admin\ExtensionsController;
use Piwigo\Controller\Admin\GroupsController;
use Piwigo\Controller\Admin\MaintenanceController;
use Piwigo\Controller\Admin\MiscController;
use Piwigo\Controller\Admin\PhotoController;
use Piwigo\Controller\Admin\UsersController;

/**
 * Validates that every page name in an admin sub-controller's PAGES array
 * is actually handled inside that controller's handle() method.
 *
 * A page in PAGES but absent from handle() would reach a dead branch —
 * the dispatch silently does nothing.
 */
final class AdminPagesDispatchTest extends TestCase
{
    /** @return array<string, array{class-string, list<string>}> */
    public static function subControllerProvider(): array
    {
        return [
            'AlbumController'         => [AlbumController::class,         AlbumController::PAGES],
            'BatchManagerController'  => [BatchManagerController::class,  BatchManagerController::PAGES],
            'ConfigurationController' => [ConfigurationController::class, ConfigurationController::PAGES],
            'ExtensionsController'    => [ExtensionsController::class,    ExtensionsController::PAGES],
            'GroupsController'        => [GroupsController::class,        GroupsController::PAGES],
            'MaintenanceController'   => [MaintenanceController::class,   MaintenanceController::PAGES],
            'MiscController'          => [MiscController::class,          MiscController::PAGES],
            'PhotoController'         => [PhotoController::class,         PhotoController::PAGES],
            'UsersController'         => [UsersController::class,         UsersController::PAGES],
        ];
    }

    /**
     * @param class-string  $controllerClass
     * @param list<string>  $pages
     */
    #[DataProvider('subControllerProvider')]
    public function test_every_page_is_handled(string $controllerClass, array $pages): void
    {
        $rfFileName = new \ReflectionClass($controllerClass)->getFileName();
        $src = file_get_contents($rfFileName !== false ? $rfFileName : '');
        self::assertIsString($src, "Could not read $controllerClass source");
        self::assertNotEmpty($src, "Could not read $controllerClass source");

        $missing = [];
        foreach ($pages as $page) {
            // Match: $page === 'name'  or  === "name"
            if (!preg_match('/\$page\s*===\s*[\'"]' . preg_quote($page, '/') . '[\'"]/', $src)) {
                $missing[] = $page;
            }
        }

        self::assertEmpty(
            $missing,
            "$controllerClass::PAGES entries with no dispatch branch in handle(): "
            . implode(', ', $missing)
        );
    }

    /**
     * B10 replaced AdminController's per-controller dispatch table with
     * a registry populated by AdminPagesRegisteringSubscriber. Verify
     * that walking the subscriber yields one registry entry per page in
     * every sub-controller's PAGES array — that's the new "every page
     * has a dispatch target" invariant.
     */
    public function test_AdminController_dispatches_all_sub_controller_PAGES(): void
    {
        $registry = new \Piwigo\Admin\AdminPageRegistry();
        (new \Piwigo\Listener\AdminPagesRegisteringSubscriber())
            ->onAdminPagesRegistering(new \Piwigo\Event\Admin\AdminPagesRegistering($registry));

        $missing = [];
        foreach (self::subControllerProvider() as [$controllerClass, $pages]) {
            foreach ($pages as $page) {
                $entry = $registry->find($page);
                if ($entry === null || $entry->controllerClass !== $controllerClass) {
                    $short = new \ReflectionClass($controllerClass)->getShortName();
                    $missing[] = "{$short}::{$page}";
                }
            }
        }

        self::assertEmpty(
            $missing,
            'AdminPagesRegisteringSubscriber missed registry entries for: '
            . implode(', ', $missing)
        );
    }
}
