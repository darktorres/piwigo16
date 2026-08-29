<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;
use Piwigo\Picture\Projection\CommentListView;
use Piwigo\Picture\Projection\CommentRow;

/**
 * pageAssets() reads exactly one field off a row -- whether the viewer
 * may delete it -- so everything else here is filler.
 */
function commentListViewRow(string $author, ?string $deleteUrl = null): CommentRow
{
    return new CommentRow(
        id: 1,
        author: $author,
        date: '',
        content: '',
        websiteUrl: null,
        deleteUrl: $deleteUrl,
    );
}

test('pageAssets registers only the 3 unconditional entries when no comment is deletable', function (): void {
    $view = new CommentListView(
        comments: [
            commentListViewRow('alice'),
        ],
        commentDerivativeParams: null,
        rootUrl: 'http://example.com/',
        iconDir: 'icon',
    );

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::css('themes/default/css/pages/comment_list.css', id: 'comment_list'),
            AssetContribution::script('jquery.ajaxmanager', 'https://cdn.jsdelivr.net/gh/aFarkas/Ajaxmanager@3.12/jquery.ajaxmanager.js', loadMode: LoadMode::Footer),
            AssetContribution::script('thumbnails.loader', 'themes/default/js/thumbnails.loader.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery.ajaxmanager']),
        ]);
});

test('pageAssets registers core_scripts_page when any comment is deletable', function (): void {
    $view = new CommentListView(
        comments: [
            commentListViewRow('alice'),
            commentListViewRow('bob', deleteUrl: 'http://example.com/delete'),
        ],
        commentDerivativeParams: null,
        rootUrl: 'http://example.com/',
        iconDir: 'icon',
    );

    expect($view->pageAssets())
        ->toContainEqual(AssetContribution::script('core_scripts_page', 'themes/default/js/pages/core_scripts.ts', loadMode: LoadMode::Footer));
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
