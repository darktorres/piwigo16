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
        $src = file_get_contents(new \ReflectionClass($controllerClass)->getFileName() ?: '');
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

    public function test_AdminController_dispatches_all_sub_controller_PAGES(): void
    {
        $adminSrc = file_get_contents(
            new \ReflectionClass(AdminController::class)->getFileName() ?: ''
        );
        self::assertIsString($adminSrc);
        self::assertNotEmpty($adminSrc);

        $allSubControllers = [
            AlbumController::class, BatchManagerController::class,
            ConfigurationController::class, ExtensionsController::class,
            GroupsController::class, MaintenanceController::class,
            PhotoController::class, UsersController::class,
        ];

        $missing = [];
        foreach ($allSubControllers as $fqcn) {
            $short = new \ReflectionClass($fqcn)->getShortName();
            if (!str_contains($adminSrc, $short . '::PAGES')) {
                $missing[] = $short;
            }
        }

        self::assertEmpty(
            $missing,
            'AdminController::dispatchToSubController() does not reference PAGES for: '
            . implode(', ', $missing)
        );
    }
}
