<?php

declare(strict_types=1);

use Piwigo\Admin\AlbumNotificationPageRenderer;
use Piwigo\Admin\BatchManager\FilterResolver;
use Piwigo\Admin\BatchManagerUnitPageRenderer;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\CatListPageRenderer;
use Piwigo\Admin\CatOptionsPageRenderer;
use Piwigo\Admin\CatPermPageRenderer;
use Piwigo\Admin\ElementSetRanksPageRenderer;
use Piwigo\Admin\GroupListPageRenderer;
use Piwigo\Admin\LanguagesInstalledPageRenderer;
use Piwigo\Admin\LanguagesNewPageRenderer;
use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Piwigo\Admin\MaintenanceActionsPageRenderer;
use Piwigo\Admin\MaintenanceEnvPageRenderer;
use Piwigo\Admin\PhotosAddDirectPageRenderer;
use Piwigo\Admin\PictureCoiPageRenderer;
use Piwigo\Admin\PictureModifyPageRenderer;
use Piwigo\Admin\PluginsNewPageRenderer;
use Piwigo\Admin\TagsPageRenderer;
use Piwigo\Admin\ThemesInstalledPageRenderer;
use Piwigo\Admin\ThemesNewPageRenderer;
use Piwigo\Admin\ThemesStandardPagesPageRenderer;
use Piwigo\Admin\UpdatesPwgPageRenderer;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Admin\UserPermPageRenderer;
use Piwigo\Bootstrap\AdminAccessor;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * Piwigo\Bootstrap\AdminAccessor -- every one of its 25 typed accessors'
 * own "Container returned an unexpected type" \LogicException guard had
 * zero coverage: the happy path (container really does resolve the real
 * class) is already exercised indirectly through the many
 * Controller\Admin\*SubController tests that reach these renderers via
 * config/admin_pages.php, but nothing ever made the container return a
 * mismatched type to reach the guard itself. KernelContainerOverride
 * rebinds exactly the one class under test to a plain stdClass per case
 * (see its own docblock), leaving every other real container definition
 * intact.
 */
afterEach(function (): void {
    Kernel::reset();
});

test('every accessor returns its real, correctly-typed instance from a real container, without throwing', function (): void {
    // The wrong-type tests below all prove the guard *fires* correctly --
    // none of them prove it *doesn't* fire for the real, correctly-typed
    // case, which is what every one of these 25 `! $x instanceof Y`
    // checks actually gates day to day. A real, fully-booted container
    // (not a KernelContainerOverride swap) is the only way to observe
    // that -- some of these definitions transitively need Piwigo\Core\Paths,
    // which Container::build() only binds when Kernel::boot() is given a
    // real Paths instance up front (it's a value passed into the
    // container builder, not something resolved from CurrentPaths at
    // lookup time), so that needs seeding too even though nothing here
    // ever touches the filesystem.
    Kernel::reset();
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));

    // Each accessor's own return type already guarantees the resolved
    // instance's class (a mismatch would throw a TypeError before this
    // array literal could even finish building) -- an explicit
    // toBeInstanceOf() per accessor would just be re-asserting what PHP
    // itself already enforces. What's actually under test is that every
    // one of the 25 calls below completes without hitting its internal
    // "Container returned an unexpected type" guard (see this test's own
    // docblock); an uncaught LogicException from any of them fails the
    // test on its own.
    $instances = [
        AdminAccessor::categoryAdminService(),
        AdminAccessor::dbMaintenanceRepository(),
        AdminAccessor::filterResolver(),
        AdminAccessor::userPermPageRenderer(),
        AdminAccessor::updatesPwgPageRenderer(),
        AdminAccessor::themesNewPageRenderer(),
        AdminAccessor::themesInstalledPageRenderer(),
        AdminAccessor::tagsPageRenderer(),
        AdminAccessor::pluginsNewPageRenderer(),
        AdminAccessor::pictureModifyPageRenderer(),
        AdminAccessor::photosAddDirectPageRenderer(),
        AdminAccessor::maintenanceEnvPageRenderer(),
        AdminAccessor::maintenanceActionsPageRenderer(),
        AdminAccessor::languagesNewPageRenderer(),
        AdminAccessor::languagesInstalledPageRenderer(),
        AdminAccessor::groupListPageRenderer(),
        AdminAccessor::elementSetRanksPageRenderer(),
        AdminAccessor::catPermPageRenderer(),
        AdminAccessor::catOptionsPageRenderer(),
        AdminAccessor::catListPageRenderer(),
        AdminAccessor::batchManagerUnitPageRenderer(),
        AdminAccessor::albumNotificationPageRenderer(),
        AdminAccessor::themesStandardPagesPageRenderer(),
        AdminAccessor::pictureCoiPageRenderer(),
        AdminAccessor::uploadService(),
    ];

    expect($instances)
        ->toHaveCount(25);
});

test('categoryAdminService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        CategoryAdminService::class,
        static fn (): CategoryAdminService => AdminAccessor::categoryAdminService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CategoryAdminService::class);

test('dbMaintenanceRepository throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        DbMaintenanceRepository::class,
        static fn (): DbMaintenanceRepository => AdminAccessor::dbMaintenanceRepository()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . DbMaintenanceRepository::class);

test('filterResolver throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        FilterResolver::class,
        static fn (): FilterResolver => AdminAccessor::filterResolver()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . FilterResolver::class);

test('userPermPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        UserPermPageRenderer::class,
        static fn (): UserPermPageRenderer => AdminAccessor::userPermPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . UserPermPageRenderer::class);

test('updatesPwgPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        UpdatesPwgPageRenderer::class,
        static fn (): UpdatesPwgPageRenderer => AdminAccessor::updatesPwgPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . UpdatesPwgPageRenderer::class);

test('themesNewPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        ThemesNewPageRenderer::class,
        static fn (): ThemesNewPageRenderer => AdminAccessor::themesNewPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ThemesNewPageRenderer::class);

test('themesInstalledPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        ThemesInstalledPageRenderer::class,
        static fn (): ThemesInstalledPageRenderer => AdminAccessor::themesInstalledPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ThemesInstalledPageRenderer::class);

test('tagsPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        TagsPageRenderer::class,
        static fn (): TagsPageRenderer => AdminAccessor::tagsPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . TagsPageRenderer::class);

test('pluginsNewPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        PluginsNewPageRenderer::class,
        static fn (): PluginsNewPageRenderer => AdminAccessor::pluginsNewPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . PluginsNewPageRenderer::class);

test('pictureModifyPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        PictureModifyPageRenderer::class,
        static fn (): PictureModifyPageRenderer => AdminAccessor::pictureModifyPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . PictureModifyPageRenderer::class);

test('photosAddDirectPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        PhotosAddDirectPageRenderer::class,
        static fn (): PhotosAddDirectPageRenderer => AdminAccessor::photosAddDirectPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . PhotosAddDirectPageRenderer::class);

test('maintenanceEnvPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        MaintenanceEnvPageRenderer::class,
        static fn (): MaintenanceEnvPageRenderer => AdminAccessor::maintenanceEnvPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . MaintenanceEnvPageRenderer::class);

test('maintenanceActionsPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        MaintenanceActionsPageRenderer::class,
        static fn (): MaintenanceActionsPageRenderer => AdminAccessor::maintenanceActionsPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . MaintenanceActionsPageRenderer::class);

test('languagesNewPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        LanguagesNewPageRenderer::class,
        static fn (): LanguagesNewPageRenderer => AdminAccessor::languagesNewPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . LanguagesNewPageRenderer::class);

test('languagesInstalledPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        LanguagesInstalledPageRenderer::class,
        static fn (): LanguagesInstalledPageRenderer => AdminAccessor::languagesInstalledPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . LanguagesInstalledPageRenderer::class);

test('groupListPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        GroupListPageRenderer::class,
        static fn (): GroupListPageRenderer => AdminAccessor::groupListPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . GroupListPageRenderer::class);

test('elementSetRanksPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        ElementSetRanksPageRenderer::class,
        static fn (): ElementSetRanksPageRenderer => AdminAccessor::elementSetRanksPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ElementSetRanksPageRenderer::class);

test('catPermPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        CatPermPageRenderer::class,
        static fn (): CatPermPageRenderer => AdminAccessor::catPermPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CatPermPageRenderer::class);

test('catOptionsPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        CatOptionsPageRenderer::class,
        static fn (): CatOptionsPageRenderer => AdminAccessor::catOptionsPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CatOptionsPageRenderer::class);

test('catListPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        CatListPageRenderer::class,
        static fn (): CatListPageRenderer => AdminAccessor::catListPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CatListPageRenderer::class);

test('batchManagerUnitPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        BatchManagerUnitPageRenderer::class,
        static fn (): BatchManagerUnitPageRenderer => AdminAccessor::batchManagerUnitPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . BatchManagerUnitPageRenderer::class);

test('albumNotificationPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        AlbumNotificationPageRenderer::class,
        static fn (): AlbumNotificationPageRenderer => AdminAccessor::albumNotificationPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . AlbumNotificationPageRenderer::class);

test('themesStandardPagesPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        ThemesStandardPagesPageRenderer::class,
        static fn (): ThemesStandardPagesPageRenderer => AdminAccessor::themesStandardPagesPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ThemesStandardPagesPageRenderer::class);

test('pictureCoiPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        PictureCoiPageRenderer::class,
        static fn (): PictureCoiPageRenderer => AdminAccessor::pictureCoiPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . PictureCoiPageRenderer::class);

test('uploadService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        UploadService::class,
        static fn (): UploadService => AdminAccessor::uploadService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . UploadService::class);
