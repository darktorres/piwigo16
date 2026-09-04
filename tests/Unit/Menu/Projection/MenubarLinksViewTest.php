<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;
use Piwigo\Menu\Projection\MenubarLinkRow;
use Piwigo\Menu\Projection\MenubarLinksView;

/**
 * Moved here from MenubarViewTest when the links block became a real View
 * rendered into its own `raw_content`: MenubarView::pageAssets() used to
 * register this script from a `match ($block->template)` arm, which stops
 * matching once the block no longer sets `template`.
 */
test('pageAssets registers the links script', function (): void {
    $view = new MenubarLinksView([
        new MenubarLinkRow('https://example.test', 'Example', null, null),
    ]);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('menubar-links', 'themes/default/js/menubarLinks.ts', loadMode: LoadMode::Footer),
        ]);
});
