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
use Piwigo\Admin\Extensions\CoreUpdateService;
use Piwigo\Admin\Extensions\ExtensionUpdateChecker;
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
use Piwigo\Admin\UserPermPageRenderer;
use Piwigo\Bootstrap\AdminAccessor;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * Piwigo\Bootstrap\AdminAccessor -- every one of its 26 typed accessors'
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
    \Piwigo\Core\Kernel::reset();
});

test('every accessor returns its real, correctly-typed instance from a real container, without throwing', function (): void {
    // The wrong-type tests below all prove the guard *fires* correctly --
    // none of them prove it *doesn't* fire for the real, correctly-typed
    // case, which is what every one of these 26 `! $x instanceof Y`
    // checks actually gates day to day. A real, fully-booted container
    // (not a KernelContainerOverride swap) is the only way to observe
    // that -- some of these definitions transitively need Piwigo\Core\Paths,
    // which Container::build() only binds when Kernel::boot() is given a
    // real Paths instance up front (it's a value passed into the
    // container builder, not something resolved from CurrentPaths at
    // lookup time), so that needs seeding too even though nothing here
    // ever touches the filesystem.
    \Piwigo\Core\Kernel::reset();
    \Piwigo\Core\Kernel::boot(\Piwigo\Core\Paths::fromRoot(sys_get_temp_dir()));

    expect(AdminAccessor::categoryAdminService())->toBeInstanceOf(CategoryAdminService::class);
    expect(AdminAccessor::extensionUpdateChecker())->toBeInstanceOf(ExtensionUpdateChecker::class);
    expect(AdminAccessor::coreUpdateService())->toBeInstanceOf(CoreUpdateService::class);
    expect(AdminAccessor::dbMaintenanceRepository())->toBeInstanceOf(DbMaintenanceRepository::class);
    expect(AdminAccessor::filterResolver())->toBeInstanceOf(FilterResolver::class);
    expect(AdminAccessor::userPermPageRenderer())->toBeInstanceOf(UserPermPageRenderer::class);
    expect(AdminAccessor::updatesPwgPageRenderer())->toBeInstanceOf(UpdatesPwgPageRenderer::class);
    expect(AdminAccessor::themesNewPageRenderer())->toBeInstanceOf(ThemesNewPageRenderer::class);
    expect(AdminAccessor::themesInstalledPageRenderer())->toBeInstanceOf(ThemesInstalledPageRenderer::class);
    expect(AdminAccessor::tagsPageRenderer())->toBeInstanceOf(TagsPageRenderer::class);
    expect(AdminAccessor::pluginsNewPageRenderer())->toBeInstanceOf(PluginsNewPageRenderer::class);
    expect(AdminAccessor::pictureModifyPageRenderer())->toBeInstanceOf(PictureModifyPageRenderer::class);
    expect(AdminAccessor::photosAddDirectPageRenderer())->toBeInstanceOf(PhotosAddDirectPageRenderer::class);
    expect(AdminAccessor::maintenanceEnvPageRenderer())->toBeInstanceOf(MaintenanceEnvPageRenderer::class);
    expect(AdminAccessor::maintenanceActionsPageRenderer())->toBeInstanceOf(MaintenanceActionsPageRenderer::class);
    expect(AdminAccessor::languagesNewPageRenderer())->toBeInstanceOf(LanguagesNewPageRenderer::class);
    expect(AdminAccessor::languagesInstalledPageRenderer())->toBeInstanceOf(LanguagesInstalledPageRenderer::class);
    expect(AdminAccessor::groupListPageRenderer())->toBeInstanceOf(GroupListPageRenderer::class);
    expect(AdminAccessor::elementSetRanksPageRenderer())->toBeInstanceOf(ElementSetRanksPageRenderer::class);
    expect(AdminAccessor::catPermPageRenderer())->toBeInstanceOf(CatPermPageRenderer::class);
    expect(AdminAccessor::catOptionsPageRenderer())->toBeInstanceOf(CatOptionsPageRenderer::class);
    expect(AdminAccessor::catListPageRenderer())->toBeInstanceOf(CatListPageRenderer::class);
    expect(AdminAccessor::batchManagerUnitPageRenderer())->toBeInstanceOf(BatchManagerUnitPageRenderer::class);
    expect(AdminAccessor::albumNotificationPageRenderer())->toBeInstanceOf(AlbumNotificationPageRenderer::class);
    expect(AdminAccessor::themesStandardPagesPageRenderer())->toBeInstanceOf(ThemesStandardPagesPageRenderer::class);
    expect(AdminAccessor::pictureCoiPageRenderer())->toBeInstanceOf(PictureCoiPageRenderer::class);
});

test('categoryAdminService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        CategoryAdminService::class,
        static fn () => AdminAccessor::categoryAdminService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CategoryAdminService::class);

test('extensionUpdateChecker throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        ExtensionUpdateChecker::class,
        static fn () => AdminAccessor::extensionUpdateChecker()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ExtensionUpdateChecker::class);

test('coreUpdateService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        CoreUpdateService::class,
        static fn () => AdminAccessor::coreUpdateService()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CoreUpdateService::class);

test('dbMaintenanceRepository throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        DbMaintenanceRepository::class,
        static fn () => AdminAccessor::dbMaintenanceRepository()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . DbMaintenanceRepository::class);

test('filterResolver throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        FilterResolver::class,
        static fn () => AdminAccessor::filterResolver()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . FilterResolver::class);

test('userPermPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        UserPermPageRenderer::class,
        static fn () => AdminAccessor::userPermPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . UserPermPageRenderer::class);

test('updatesPwgPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        UpdatesPwgPageRenderer::class,
        static fn () => AdminAccessor::updatesPwgPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . UpdatesPwgPageRenderer::class);

test('themesNewPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        ThemesNewPageRenderer::class,
        static fn () => AdminAccessor::themesNewPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ThemesNewPageRenderer::class);

test('themesInstalledPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        ThemesInstalledPageRenderer::class,
        static fn () => AdminAccessor::themesInstalledPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ThemesInstalledPageRenderer::class);

test('tagsPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        TagsPageRenderer::class,
        static fn () => AdminAccessor::tagsPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . TagsPageRenderer::class);

test('pluginsNewPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        PluginsNewPageRenderer::class,
        static fn () => AdminAccessor::pluginsNewPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . PluginsNewPageRenderer::class);

test('pictureModifyPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        PictureModifyPageRenderer::class,
        static fn () => AdminAccessor::pictureModifyPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . PictureModifyPageRenderer::class);

test('photosAddDirectPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        PhotosAddDirectPageRenderer::class,
        static fn () => AdminAccessor::photosAddDirectPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . PhotosAddDirectPageRenderer::class);

test('maintenanceEnvPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        MaintenanceEnvPageRenderer::class,
        static fn () => AdminAccessor::maintenanceEnvPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . MaintenanceEnvPageRenderer::class);

test('maintenanceActionsPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        MaintenanceActionsPageRenderer::class,
        static fn () => AdminAccessor::maintenanceActionsPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . MaintenanceActionsPageRenderer::class);

test('languagesNewPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        LanguagesNewPageRenderer::class,
        static fn () => AdminAccessor::languagesNewPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . LanguagesNewPageRenderer::class);

test('languagesInstalledPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        LanguagesInstalledPageRenderer::class,
        static fn () => AdminAccessor::languagesInstalledPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . LanguagesInstalledPageRenderer::class);

test('groupListPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        GroupListPageRenderer::class,
        static fn () => AdminAccessor::groupListPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . GroupListPageRenderer::class);

test('elementSetRanksPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        ElementSetRanksPageRenderer::class,
        static fn () => AdminAccessor::elementSetRanksPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ElementSetRanksPageRenderer::class);

test('catPermPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        CatPermPageRenderer::class,
        static fn () => AdminAccessor::catPermPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CatPermPageRenderer::class);

test('catOptionsPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        CatOptionsPageRenderer::class,
        static fn () => AdminAccessor::catOptionsPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CatOptionsPageRenderer::class);

test('catListPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        CatListPageRenderer::class,
        static fn () => AdminAccessor::catListPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CatListPageRenderer::class);

test('batchManagerUnitPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        BatchManagerUnitPageRenderer::class,
        static fn () => AdminAccessor::batchManagerUnitPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . BatchManagerUnitPageRenderer::class);

test('albumNotificationPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        AlbumNotificationPageRenderer::class,
        static fn () => AdminAccessor::albumNotificationPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . AlbumNotificationPageRenderer::class);

test('themesStandardPagesPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        ThemesStandardPagesPageRenderer::class,
        static fn () => AdminAccessor::themesStandardPagesPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ThemesStandardPagesPageRenderer::class);

test('pictureCoiPageRenderer throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        PictureCoiPageRenderer::class,
        static fn () => AdminAccessor::pictureCoiPageRenderer()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . PictureCoiPageRenderer::class);
