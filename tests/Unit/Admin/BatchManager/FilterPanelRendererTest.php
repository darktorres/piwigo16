<?php

declare(strict_types=1);

use Piwigo\Admin\BatchManager\Projection\BulkManagerFilter;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\BatchManager\FilterPanelRenderer;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;

/**
 * Piwigo\Admin\BatchManager\FilterPanelRenderer -- has no constructor
 * (B5's own T1 pattern). No dedicated Integration/Browser spec of its
 * own -- shared by BatchManagerGlobalPageRenderer/BatchManagerUnitPageRenderer.
 *
 * Only the no-active-filter, no-selected-elements happy path is covered:
 * an empty/unset $_SESSION['bulk_manager_filter'] skips the real
 * TagService::getTagListByIds()/HtmlService::getCatDisplayNameFromId()
 * calls, and an empty $catElementsId skips the real
 * ImageRepository::findVirtuallyAssociatedCategoryRows() DB call --
 * TagService is still a required param regardless (never actually
 * queried on this path, matching this campaign's established
 * "type-satisfying instance" reasoning).
 *
 * Unlike every other renderer test in this campaign, this class takes a
 * real Template directly (not CurrentTemplate) and never renders a
 * template of its own -- no .latte fixture file needed at all.
 */
function filterPanelTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-filter-panel-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function filterPanelTestRrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        is_dir($path) ? filterPanelTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function filterPanelTestActivityService(): ActivityService
{
    return new ActivityService(TypedRepository::narrow(EntityManagerFactory::build(DbConnection::build())->getRepository(ActivityEntity::class), ActivityRepository::class));
}

function filterPanelTestTagService(): TagService
{
    $conn = DbConnection::build();
    $currentUser = CurrentUserTestFactory::get();

    return new TagService(
        LangTestFactory::get(),
        TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(TagEntity::class), TagRepository::class),
        new PermissionService(
            new PermissionRepository(EntityManagerFactory::build($conn)),
            TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), GroupRepository::class),
            new CategoryRepository(EntityManagerFactory::build($conn), new CurrentConfig()),
            $currentUser,
            new FilterState(),
            new AccessLevelChecker($currentUser, new CurrentConfig()),
        ),
        filterPanelTestActivityService(),
        new EventDispatcher(),
        $currentUser,
        new CurrentConfig(),
        new CurrentLogger(),
    );
}

test('render() shows every built-in prefilter and no active filter/selection when nothing is set', function (): void {
    $root = filterPanelTestRoot();
    unset($_SESSION['bulk_manager_filter']);

    try {
        $template = TemplateTestFactory::build();
        $pageState = new PageState();

        new FilterPanelRenderer()
            ->render(
                LangTestFactory::get(),
                $template,
                '/admin.php?page=batch_manager',
                [],
                [],
                0,
                UrlServiceTestFactory::build(),
                new EventDispatcher(),
                $pageState,
                filterPanelTestTagService(),
                HtmlServiceTestFactory::build(),
                CurrentConfigTestFactory::get(),
                EntityManagerFactory::build(DbConnection::build()),
                new CsrfService(CurrentConfigTestFactory::get()),
            );

        $prefilters = $template->getTemplateVars('prefilters');
        if (! is_array($prefilters)) {
            throw new LogicException('expected an array of prefilters');
        }
        $ids = array_map(static fn (mixed $row): mixed => is_array($row) ? ($row['ID'] ?? null) : null, $prefilters);

        // Alphabetically sorted by each entry's own localized NAME (an
        // untranslated Lang here just echoes the English key back) -- a
        // fresh CurrentConfig defaults enableSynchronization() to true,
        // adding 'no_sync_md5sum'/'no_virtual_album' to the built-in 7.
        expect($ids)
            ->toBe(['all_photos', 'caddie', 'duplicates', 'last_import', 'no_album', 'no_sync_md5sum', 'no_tag', 'no_virtual_album', 'favorites'])
            // The filter reaches the template as BulkManagerFilter now, not
            // the raw session array (P58-B3): an empty session bag becomes a
            // VO whose every field is at its "not enabled" default, which is
            // the same statement the old `toBe([])` was making about the
            // array.
            ->and($template->getTemplateVars('filter'))
            ->toEqual(BulkManagerFilter::fromArray([]))
            ->and($template->getTemplateVars('selection'))
            ->toBe([])
            ->and($template->getTemplateVars('associated_categories'))
            ->toBe([])
            ->and($template->getTemplateVars('filter_tags'))
            ->toBe([])
            ->and($template->getTemplateVars('ADMIN_PAGE_TITLE'))
            ->toBe('Batch Manager');
    } finally {
        CurrentConfigTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        filterPanelTestRrmdir($root);
    }
});
