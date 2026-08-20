<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;
use Piwigo\Picture\Projection\CommentListView;

test('pageAssets registers only the 3 unconditional entries when no comment has U_DELETE', function (): void {
    $view = new CommentListView(
        comments: [
            [
                'AUTHOR' => 'alice',
            ],
        ],
        commentDerivativeParams: null,
        rootUrl: 'http://example.com/',
        iconDir: 'icon',
    );

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::css('themes/default/css/pages/comment_list.css', id: 'comment_list'),
            AssetContribution::script('jquery.ajaxmanager', 'themes/default/js/plugins/jquery.ajaxmanager.js', loadMode: LoadMode::Footer),
            AssetContribution::script('thumbnails.loader', 'themes/default/js/thumbnails.loader.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ajaxmanager', 'page-data']),
        ]);
});

test('pageAssets registers core.scripts when any comment has U_DELETE', function (): void {
    $view = new CommentListView(
        comments: [
            [
                'AUTHOR' => 'alice',
            ],
            [
                'AUTHOR' => 'bob',
                'U_DELETE' => 'http://example.com/delete',
            ],
        ],
        commentDerivativeParams: null,
        rootUrl: 'http://example.com/',
        iconDir: 'icon',
    );

    expect($view->pageAssets())
        ->toContainEqual(AssetContribution::script('core.scripts', 'themes/default/js/scripts.js', loadMode: LoadMode::Footer, dependsOn: ['page-data']));
});

test('exposedPageData omits error_icon when commentDerivativeParams is null', function (): void {
    $view = new CommentListView(
        comments: [],
        commentDerivativeParams: null,
        rootUrl: 'http://example.com/',
        iconDir: 'icon',
    );

    expect($view->exposedPageData())
        ->toBe([]);
});
