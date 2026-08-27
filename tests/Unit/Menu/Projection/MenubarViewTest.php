<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;
use Piwigo\Menu\DisplayBlock;
use Piwigo\Menu\Projection\MenubarView;
use Piwigo\Menu\RegisteredBlock;

/**
 * @param array<int|string, mixed> $data
 */
function makeMenubarDisplayBlock(string $template, array|null $data = null): DisplayBlock
{
    $block = new DisplayBlock(new RegisteredBlock('id', 'name', 'owner'));
    $block->template = $template;
    $block->data = $data;

    return $block;
}

test('pageAssets registers menubar_identification.latte\'s own CSS when that block is present', function (): void {
    $view = new MenubarView([
        makeMenubarDisplayBlock('menubar_identification.latte'),
    ]);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::css('themes/default/css/components/menubar_identification.css', id: 'menubar_identification'),
        ]);
});

test('pageAssets registers menubar_links.latte\'s own script when that block is present', function (): void {
    $view = new MenubarView([
        makeMenubarDisplayBlock('menubar_links.latte'),
    ]);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('menubar-links', 'themes/default/js/menubar-links.ts', loadMode: LoadMode::Footer),
        ]);
});

test('pageAssets registers menubar_menu.latte\'s quicksearch assets when qsearch is true', function (): void {
    $view = new MenubarView([
        makeMenubarDisplayBlock('menubar_menu.latte', [
            'qsearch' => true,
        ]),
    ]);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('menubar-quicksearch', 'themes/default/js/menubar-quicksearch.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/default/css/components/menubar_menu.css', id: 'menubar_menu'),
        ]);

    expect($view->exposedStrings())
        ->toBe(['Quick search']);
});

test('pageAssets skips menubar_menu.latte\'s quicksearch assets when qsearch is absent', function (): void {
    $view = new MenubarView([
        makeMenubarDisplayBlock('menubar_menu.latte', [
            'tags' => [],
        ]),
    ]);

    expect($view->pageAssets())
        ->toEqual([]);

    expect($view->exposedStrings())
        ->toBe([]);
});

test('pageAssets ignores an unrecognized (plugin) block template', function (): void {
    $view = new MenubarView([
        makeMenubarDisplayBlock('menubar_categories.latte'),
        makeMenubarDisplayBlock('some_plugin_block.latte'),
    ]);

    expect($view->pageAssets())
        ->toEqual([]);
});

test('pageAssets preserves block iteration order across multiple recognized blocks', function (): void {
    $view = new MenubarView([
        makeMenubarDisplayBlock('menubar_links.latte'),
        makeMenubarDisplayBlock('menubar_identification.latte'),
    ]);

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::script('menubar-links', 'themes/default/js/menubar-links.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/default/css/components/menubar_identification.css', id: 'menubar_identification'),
        ]);
});
