<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `admin.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\AdminShell::runDispatch()} right before its final render
 * -- the `<div id="menubar">` sidebar-nav fields that only `admin.latte`
 * itself reads. `$activeMenu` is the former `AdminShellPostDispatchPageContext`'s
 * own field, folded in here since nothing else reads it ambiently
 * (confirmed: unlike this file's other properties, no other admin
 * template references `$ACTIVE_MENU`). `$activeMenu`'s own sibling
 * `$pwgmenu` is dropped rather than carried forward: confirmed dead
 * (assigned, never read by any real template) -- `AdminShellPostDispatchPageContext`
 * itself is deleted in the same commit as this conversion, having no
 * other real caller.
 *
 * `AdminShellFramePageContext` keeps being assigned ambiently, unchanged,
 * alongside this View -- several other admin sub-page templates
 * (`intro.latte`, `help.latte`, `photos_add_ftp.latte`,
 * `include/batch_manager_filter.inc.latte`) read a subset of its same
 * fields ambiently during `AdminDispatcher::dispatch()`, before this View
 * is ever constructed. `$adminPageTitle`/`$adminPageObjectId` stay
 * ambient too (via {@see \Piwigo\Controller\Admin\Projection\AdminContentPageContext}),
 * not duplicated here: `admin.latte`'s own `<h1>` reads whichever of the
 * two most recently assigned -- the shell's own generic default, or a
 * sub-controller's more specific override -- so it can't become a fixed
 * property on this page-specific View.
 */
#[Template('admin.latte')]
final readonly class AdminShellView implements View, HasPageAssets, ExposesPageData
{
    public function __construct(
        public int $activeMenu,
        public bool $hasHelp,
        public bool $enableSynchronization,
        public string $uHistoryStat,
        public string $uMaintenance,
        public string $uNotificationByMail,
        public string $uConfigGeneral,
        public string $uConfigMenubar,
        public string $uConfigLanguages,
        public string $uConfigThemes,
        public string $uAlbums,
        public string $uCatOptions,
        public string $uCatUpdate,
        public string $uRating,
        public string $uRecentSet,
        public string $uBatch,
        public string $uTags,
        public string $uUsers,
        public string $uGroups,
        public string $uAdmin,
        public string $uPlugins,
        public string $uAddPhotos,
        public bool $showRating,
        public ?string $uUpdates,
        public ?string $uComments,
        public ?int $nbPendingComments,
        public int $nbPhotosInCaddie,
        public string $uCaddie,
        public int $nbOrphans,
        public string $uOrphans,
    ) {}

    /**
     * `admin_help`/`include/colorbox.inc.latte`'s own contribution is
     * genuinely conditional -- both sat inside the template's own
     * `{if isset($U_HELP)}` block, gated on `AdminContentPageContext::
     * $helpUrl` (per-sub-page ambient, assigned before this View is
     * constructed -- see this class's own docblock). `$hasHelp` mirrors
     * that same `isset()` check.
     *
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        $assets = [
            AssetContribution::script('admin', 'themes/admin/default/js/admin.ts', loadMode: LoadMode::Footer),
        ];

        if ($this->hasHelp) {
            $assets = [
                ...$assets,
                ...new ColorboxView()
                    ->pageAssets(),
                AssetContribution::script('admin_help', 'themes/admin/default/js/adminHelp.ts', loadMode: LoadMode::Footer),
            ];
        }

        return $assets;
    }

    /**
     * @return array<string, string|int|float|bool|null|array<mixed>>
     */
    #[Override]
    public function exposedPageData(): array
    {
        return [
            'active_menu' => $this->activeMenu,
        ];
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [];
    }
}
