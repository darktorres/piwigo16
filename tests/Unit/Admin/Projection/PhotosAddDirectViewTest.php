<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\FormatsOriginalInfo;
use Piwigo\Admin\Projection\PhotosAddDirectView;

function makePhotosAddDirectView(
    ?FormatsOriginalInfo $formatsOriginalInfo = null,
): PhotosAddDirectView {
    return new PhotosAddDirectView(
        promoteMobileApps: false,
        phpwgUrl: '',
        enableFormats: false,
        displayFormats: false,
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
    );
}

test('exposedPageData derives original_image_id_str from formatsOriginalInfo id', function (): void {
    $view = makePhotosAddDirectView(formatsOriginalInfo: new FormatsOriginalInfo(
        id: 42,
        name: '',
        src: '',
        formats: null,
        ext: '',
        editUrl: '',
    ));

    expect($view->exposedPageData()['original_image_id_str'])
        ->toBe(' 42 ');
});

test('exposedPageData falls back to -1 when there is no formatsOriginalInfo', function (): void {
    $view = makePhotosAddDirectView(formatsOriginalInfo: null);

    expect($view->exposedPageData()['original_image_id_str'])
        ->toBe(' -1 ');
});
