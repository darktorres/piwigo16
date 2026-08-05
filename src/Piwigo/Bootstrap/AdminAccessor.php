<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use LogicException;
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
use Piwigo\Admin\UserPermPageRenderer;
use Piwigo\Core\Kernel;

/**
 * DI-migration follow-on to gap-closure Stage 4: typed accessors to
 * container-resolved `Admin\*` (L4Integration, same layer as `Bootstrap`
 * itself) classes -- see `Bootstrap\CoreDomainAccessor`'s own docblock for
 * the full rationale. A same-layer dependency (Bootstrap -> Admin) is
 * already an established, real pattern in this codebase (e.g.
 * `Admin\AdminShell` already depends on `Bootstrap\*`, and
 * `Controller\Admin\*` sub-controllers already depend on `Admin\*`
 * renderers directly) -- deptrac's ruleset only restricts *upward*
 * cross-layer dependencies, not same-layer ones.
 *
 * Every one of these was previously constructed manually from a sibling
 * `Controller\Admin\*SubController` (itself already container-resolved via
 * `config/admin_pages.php`, re-passing its own constructor-injected
 * `RedirectServiceInterface`/`UrlServiceInterface`/`ConfigService`)
 * rather than resolved directly -- routing through this accessor instead
 * is uniform with every other bucket-A class this DI phase retargeted,
 * even though the more surgical fix (constructor-inject the renderer
 * directly into its one real SubController caller) would also work; at
 * this scale, uniformity was chosen over chasing the marginally purer
 * topology for each of ~20 near-identical single-call-site renderers.
 */
final class AdminAccessor
{
    public static function categoryAdminService(): CategoryAdminService
    {
        $service = Kernel::container()->get(CategoryAdminService::class);
        if (! $service instanceof CategoryAdminService) {
            throw new LogicException('Container returned an unexpected type for ' . CategoryAdminService::class);
        }
        return $service;
    }

    public static function dbMaintenanceRepository(): DbMaintenanceRepository
    {
        $service = Kernel::container()->get(DbMaintenanceRepository::class);
        if (! $service instanceof DbMaintenanceRepository) {
            throw new LogicException('Container returned an unexpected type for ' . DbMaintenanceRepository::class);
        }
        return $service;
    }

    public static function filterResolver(): FilterResolver
    {
        $service = Kernel::container()->get(FilterResolver::class);
        if (! $service instanceof FilterResolver) {
            throw new LogicException('Container returned an unexpected type for ' . FilterResolver::class);
        }
        return $service;
    }

    public static function userPermPageRenderer(): UserPermPageRenderer
    {
        $service = Kernel::container()->get(UserPermPageRenderer::class);
        if (! $service instanceof UserPermPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . UserPermPageRenderer::class);
        }
        return $service;
    }

    public static function updatesPwgPageRenderer(): UpdatesPwgPageRenderer
    {
        $service = Kernel::container()->get(UpdatesPwgPageRenderer::class);
        if (! $service instanceof UpdatesPwgPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . UpdatesPwgPageRenderer::class);
        }
        return $service;
    }

    public static function themesNewPageRenderer(): ThemesNewPageRenderer
    {
        $service = Kernel::container()->get(ThemesNewPageRenderer::class);
        if (! $service instanceof ThemesNewPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . ThemesNewPageRenderer::class);
        }
        return $service;
    }

    public static function themesInstalledPageRenderer(): ThemesInstalledPageRenderer
    {
        $service = Kernel::container()->get(ThemesInstalledPageRenderer::class);
        if (! $service instanceof ThemesInstalledPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . ThemesInstalledPageRenderer::class);
        }
        return $service;
    }

    public static function tagsPageRenderer(): TagsPageRenderer
    {
        $service = Kernel::container()->get(TagsPageRenderer::class);
        if (! $service instanceof TagsPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . TagsPageRenderer::class);
        }
        return $service;
    }

    public static function pluginsNewPageRenderer(): PluginsNewPageRenderer
    {
        $service = Kernel::container()->get(PluginsNewPageRenderer::class);
        if (! $service instanceof PluginsNewPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . PluginsNewPageRenderer::class);
        }
        return $service;
    }

    public static function pictureModifyPageRenderer(): PictureModifyPageRenderer
    {
        $service = Kernel::container()->get(PictureModifyPageRenderer::class);
        if (! $service instanceof PictureModifyPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . PictureModifyPageRenderer::class);
        }
        return $service;
    }

    public static function photosAddDirectPageRenderer(): PhotosAddDirectPageRenderer
    {
        $service = Kernel::container()->get(PhotosAddDirectPageRenderer::class);
        if (! $service instanceof PhotosAddDirectPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . PhotosAddDirectPageRenderer::class);
        }
        return $service;
    }

    public static function maintenanceEnvPageRenderer(): MaintenanceEnvPageRenderer
    {
        $service = Kernel::container()->get(MaintenanceEnvPageRenderer::class);
        if (! $service instanceof MaintenanceEnvPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . MaintenanceEnvPageRenderer::class);
        }
        return $service;
    }

    public static function maintenanceActionsPageRenderer(): MaintenanceActionsPageRenderer
    {
        $service = Kernel::container()->get(MaintenanceActionsPageRenderer::class);
        if (! $service instanceof MaintenanceActionsPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . MaintenanceActionsPageRenderer::class);
        }
        return $service;
    }

    public static function languagesNewPageRenderer(): LanguagesNewPageRenderer
    {
        $service = Kernel::container()->get(LanguagesNewPageRenderer::class);
        if (! $service instanceof LanguagesNewPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . LanguagesNewPageRenderer::class);
        }
        return $service;
    }

    public static function languagesInstalledPageRenderer(): LanguagesInstalledPageRenderer
    {
        $service = Kernel::container()->get(LanguagesInstalledPageRenderer::class);
        if (! $service instanceof LanguagesInstalledPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . LanguagesInstalledPageRenderer::class);
        }
        return $service;
    }

    public static function groupListPageRenderer(): GroupListPageRenderer
    {
        $service = Kernel::container()->get(GroupListPageRenderer::class);
        if (! $service instanceof GroupListPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . GroupListPageRenderer::class);
        }
        return $service;
    }

    public static function elementSetRanksPageRenderer(): ElementSetRanksPageRenderer
    {
        $service = Kernel::container()->get(ElementSetRanksPageRenderer::class);
        if (! $service instanceof ElementSetRanksPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . ElementSetRanksPageRenderer::class);
        }
        return $service;
    }

    public static function catPermPageRenderer(): CatPermPageRenderer
    {
        $service = Kernel::container()->get(CatPermPageRenderer::class);
        if (! $service instanceof CatPermPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . CatPermPageRenderer::class);
        }
        return $service;
    }

    public static function catOptionsPageRenderer(): CatOptionsPageRenderer
    {
        $service = Kernel::container()->get(CatOptionsPageRenderer::class);
        if (! $service instanceof CatOptionsPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . CatOptionsPageRenderer::class);
        }
        return $service;
    }

    public static function catListPageRenderer(): CatListPageRenderer
    {
        $service = Kernel::container()->get(CatListPageRenderer::class);
        if (! $service instanceof CatListPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . CatListPageRenderer::class);
        }
        return $service;
    }

    public static function batchManagerUnitPageRenderer(): BatchManagerUnitPageRenderer
    {
        $service = Kernel::container()->get(BatchManagerUnitPageRenderer::class);
        if (! $service instanceof BatchManagerUnitPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . BatchManagerUnitPageRenderer::class);
        }
        return $service;
    }

    public static function albumNotificationPageRenderer(): AlbumNotificationPageRenderer
    {
        $service = Kernel::container()->get(AlbumNotificationPageRenderer::class);
        if (! $service instanceof AlbumNotificationPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . AlbumNotificationPageRenderer::class);
        }
        return $service;
    }

    public static function themesStandardPagesPageRenderer(): ThemesStandardPagesPageRenderer
    {
        $service = Kernel::container()->get(ThemesStandardPagesPageRenderer::class);
        if (! $service instanceof ThemesStandardPagesPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . ThemesStandardPagesPageRenderer::class);
        }
        return $service;
    }

    public static function pictureCoiPageRenderer(): PictureCoiPageRenderer
    {
        $service = Kernel::container()->get(PictureCoiPageRenderer::class);
        if (! $service instanceof PictureCoiPageRenderer) {
            throw new LogicException('Container returned an unexpected type for ' . PictureCoiPageRenderer::class);
        }
        return $service;
    }
}
