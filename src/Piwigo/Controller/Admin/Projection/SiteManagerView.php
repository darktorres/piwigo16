<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `site_manager.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\SiteManagerSubController::handle()}.
 * `$sites` is always included (even empty) since `site_manager.latte`
 * reads it with `{if !empty($sites)}`, not `isset()`.
 */
#[Template('site_manager.latte')]
final readonly class SiteManagerView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param list<SiteRow> $sites
     */
    public function __construct(
        public string $formAction,
        public string $csrfToken,
        public array $sites,
    ) {}

    /**
     * `site_manager.latte`'s own unconditional `{do combineScript(...)}`x3/
     * `{do combineCss(...)}`x2 (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.css'),
            AssetContribution::css('themes/admin/default/css/pages/site_manager.css', id: 'site_manager'),
            AssetContribution::script('site_manager', 'themes/admin/default/js/site_manager.ts', loadMode: LoadMode::Footer),
        ];
    }

    #[Override]
    public function exposedPageData(): array
    {
        return [];
    }

    /**
     * `site_manager.latte`'s own unconditional `{do exposeString(...)}`x3
     * (docs/PLAN.md's P42-B) -- `'Yes, I am sure'`/`'No, I have changed
     * my mind'` are dropped outright, not ported here: 2 of the 3
     * theme-base confirm-dialog strings `ThemeBaseAssets` already
     * registers unconditionally for every page.
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Are you sure you want to delete this site?',
        ];
    }
}
