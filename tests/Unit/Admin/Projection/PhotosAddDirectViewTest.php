<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\PhotosAddDirectView;

/**
 * @param array<array-key, mixed>|null $formatsOriginalInfo
 */
function makePhotosAddDirectView(
    ?array $formatsOriginalInfo = null,
    string $pluploadCode = 'xx',
): PhotosAddDirectView {
    return new PhotosAddDirectView(
        promoteMobileApps: false,
        phpwgUrl: '',
        enableFormats: false,
        displayFormats: false,
        haveFormatsOriginal: false,
        formatsOriginalInfo: $formatsOriginalInfo,
        formatsExtInfo: null,
        switchFormatModeUrl: '',
        formatExt: '',
        strFormatExt: '',
        chunkSize: 0,
        maxFileSize: 0,
        maxUploadWidth: null,
        maxUploadHeight: null,
        maxUploadResolution: null,
        originalResizeMaxwidth: null,
        originalResizeMaxheight: null,
        formAction: '',
        pwgToken: '',
        uploadFileTypes: '',
        fileExts: '',
        addToAlbum: null,
        selectedCategoryName: null,
        selectedCategory: [],
        nbAlbums: 0,
        setupErrors: [],
        setupWarnings: [],
        hideWarningsLink: null,
        colorscheme: 'light',
        pluploadCode: $pluploadCode,
    );
}

test('pageAssets skips the plupload i18n script for a locale plupload does not ship', function (): void {
    $view = makePhotosAddDirectView(pluploadCode: 'xx');

    $ids = array_map(static fn ($asset) => $asset->id, $view->pageAssets());

    expect($ids)
        ->not->toContain('plupload_i18n-xx');
});

test('pageAssets registers the plupload i18n script for a locale plupload ships', function (): void {
    $view = makePhotosAddDirectView(pluploadCode: 'fr');

    $ids = array_map(static fn ($asset) => $asset->id, $view->pageAssets());

    expect($ids)
        ->toContain('plupload_i18n-fr');
});

test('exposedPageData derives original_image_id_str from formatsOriginalInfo id', function (): void {
    $view = makePhotosAddDirectView(formatsOriginalInfo: [
        'id' => 42,
    ]);

    expect($view->exposedPageData()['original_image_id_str'])
        ->toBe(' 42 ');
});

test('exposedPageData falls back to -1 when formatsOriginalInfo has no id', function (): void {
    $view = makePhotosAddDirectView(formatsOriginalInfo: null);

    expect($view->exposedPageData()['original_image_id_str'])
        ->toBe(' -1 ');
});
