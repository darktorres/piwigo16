<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;
use Piwigo\Menu\Projection\MenubarMenuRow;
use Piwigo\Menu\Projection\MenubarMenuView;

function makeMenubarMenuView(bool $quickSearch): MenubarMenuView
{
    return new MenubarMenuView(
        quickSearch: $quickSearch,
        links: [new MenubarMenuRow('tags.php', 'Tags', counter: 3)],
        rootUrl: '',
        querySearch: null,
    );
}

/**
 * Both moved here from MenubarViewTest with the assets themselves --
 * MenubarView dispatched on `$block->template`, which this block no
 * longer sets. `$quickSearch` stays a real branch rather than an
 * assumption: it is plugin-mutable through BlockManagerPrepareDisplay.
 */
test('pageAssets and exposedStrings carry the quick-search form when it is shown', function (): void {
    $view = makeMenubarMenuView(quickSearch: true);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('menubar-quicksearch', 'themes/default/js/menubarQuicksearch.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/default/css/components/menubar_menu.css', id: 'menubar_menu'),
        ]);
    expect($view->exposedStrings())
        ->toBe(['Quick search']);
});

test('pageAssets and exposedStrings are both empty when the quick-search form is hidden', function (): void {
    $view = makeMenubarMenuView(quickSearch: false);

    expect($view->pageAssets())
        ->toBe([]);
    expect($view->exposedStrings())
        ->toBe([]);
});
