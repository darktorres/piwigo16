<?php

declare(strict_types=1);

use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\LoadMode;
use Piwigo\Controller\Projection\PictureView;

/**
 * @param array{id: int}|null $navCurrent
 * @param array{F_ACTION: string, USER_RATE: ?int, marks: list<int>}|null $rating
 * @param array<string, mixed>|null $navNext
 */
function makePictureView(
    ?array $navCurrent = [
        'id' => 42,
    ],
    ?string $uOriginal = null,
    ?array $rating = null,
    ?array $navNext = null,
): PictureView {
    return new PictureView(
        navFirst: null,
        navPrevious: null,
        navNext: $navNext,
        navLast: null,
        navCurrent: $navCurrent,
        uSlideshowStop: null,
        slideshowNav: null,
        uSlideshowStart: null,
        sectionTitle: '',
        photo: '1/1',
        isHome: false,
        levelSeparator: ' / ',
        uUp: 'http://example.com/up',
        displayNavButtons: true,
        displayNavThumb: true,
        uMetadata: null,
        uSetAsRepresentative: null,
        uPhotoAdmin: null,
        uCaddie: null,
        favorite: null,
        commentImg: null,
        infoAuthor: null,
        infoCreationDate: null,
        infoPostedDate: '',
        infoDimensions: null,
        infoFilesize: null,
        infoVisits: '',
        infoFile: '',
        displayInfo: [],
        pdfNbPages: null,
        elementContent: '',
        uPrefetch: null,
        relatedTags: null,
        relatedCategories: null,
        csrfToken: 'token',
        cookiePath: '/',
        uOriginal: $uOriginal,
        pluginPictureButtons: [],
        pluginPictureActions: [],
        pluginPictureInfoRows: [],
        metadata: null,
        rateSummary: null,
        rating: $rating,
        commentsOrderUrl: null,
        commentsOrderTitle: null,
        commentCount: null,
        commentsNavbar: null,
        comments: null,
        commentAdd: null,
        commentList: null,
        rootUrl: 'http://example.com/',
    );
}

test('pageAssets registers only the 3 unconditional entries when uOriginal and rating are both null', function (): void {
    $view = makePictureView();

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::css('themes/default/css/pages/picture.css', id: 'picture'),
            AssetContribution::script('picture', 'themes/default/js/picture.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery', 'page-data']),
            AssetContribution::script('picture_nav_buttons', 'themes/default/js/picture_nav_buttons.ts', loadMode: LoadMode::Footer, dependsOn: ['page-data']),
        ]);
});

test('pageAssets registers only the 2 unconditional entries when uOriginal is set (no separate core.scripts/core.switchbox any more, picture.ts already carries both via ?dup)', function (): void {
    $view = makePictureView(uOriginal: 'http://example.com/original.jpg');

    expect($view->pageAssets())
        ->toEqual([
            AssetContribution::css('themes/default/css/pages/picture.css', id: 'picture'),
            AssetContribution::script('picture', 'themes/default/js/picture.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery', 'page-data']),
            AssetContribution::script('picture_nav_buttons', 'themes/default/js/picture_nav_buttons.ts', loadMode: LoadMode::Footer, dependsOn: ['page-data']),
        ]);
});

test('pageAssets registers rating when rating is set (no separate core.scripts dependsOn any more, rating.ts already carries it via ?dup)', function (): void {
    $view = makePictureView(rating: [
        'F_ACTION' => '',
        'USER_RATE' => null,
        'marks' => [1, 2, 3],
    ]);

    $assets = $view->pageAssets();

    expect($assets)
        ->toContainEqual(AssetContribution::script('rating', 'themes/default/js/rating.ts', loadMode: LoadMode::Async));
});

test('exposedPageData includes image_id and merges in picture_nav_buttons data', function (): void {
    $view = makePictureView(navNext: [
        'U_IMG' => 'http://example.com/next',
    ]);

    expect($view->exposedPageData())
        ->toBe([
            'cookie_path' => '/',
            'root_url' => 'http://example.com/',
            'image_id' => 42,
            'csrf_token' => 'token',
            'nav_next_url' => 'http://example.com/next',
            'nav_up_url' => 'http://example.com/up',
        ]);
});
