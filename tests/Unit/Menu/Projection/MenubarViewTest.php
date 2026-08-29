<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Menu\DisplayBlock;
use Piwigo\Menu\Projection\MenubarView;
use Piwigo\Menu\RegisteredBlock;

function makeMenubarDisplayBlock(string $template): DisplayBlock
{
    $block = new DisplayBlock(new RegisteredBlock('id', 'name', 'owner'));
    $block->template = $template;

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

test('pageAssets ignores an unrecognized (plugin) block template', function (): void {
    $view = new MenubarView([
        makeMenubarDisplayBlock('menubar_categories.latte'),
        makeMenubarDisplayBlock('some_plugin_block.latte'),
    ]);

    expect($view->pageAssets())
        ->toEqual([]);
});
