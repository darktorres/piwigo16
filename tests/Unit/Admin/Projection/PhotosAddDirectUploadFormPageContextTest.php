<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\PhotosAddDirectUploadFormPageContext;

test('toArray flattens every fixed property, and omits every optional key when null', function (): void {
    $context = new PhotosAddDirectUploadFormPageContext(
        fAddAction: '/admin.php?page=photos_add',
        chunkSize: 500000,
        maxFileSize: 10000000,
        adminPageTitle: 'Upload Photos',
        maxUploadWidth: null,
        maxUploadHeight: null,
        maxUploadResolution: null,
        originalResizeMaxwidth: null,
        originalResizeMaxheight: null,
        formAction: '/admin.php?page=photos_add',
        pwgToken: 'abc123',
        uploadFileTypes: 'jpg, png',
        fileExts: 'jpg,png',
        addToAlbum: null,
        selectedCategoryName: null,
        selectedCategory: [],
        nbAlbums: 3,
        levelOptions: [0 => 'Everybody'],
        levelOptionsSelected: [0],
        setupErrors: [],
        cacheKeys: ['categories' => '123_4'],
        setupWarnings: null,
        hideWarningsLink: null,
    );

    $result = $context->toArray();

    expect($result)->not->toHaveKeys(['max_upload_width', 'max_upload_height', 'max_upload_resolution', 'original_resize_maxwidth', 'original_resize_maxheight', 'ADD_TO_ALBUM', 'selected_category_name', 'setup_warnings', 'hide_warnings_link'])
        ->and($result['F_ADD_ACTION'])->toBe('/admin.php?page=photos_add')
        ->and($result['chunk_size'])->toBe(500000)
        ->and($result['NB_ALBUMS'])->toBe(3)
        ->and($result['CACHE_KEYS'])->toBe(['categories' => '123_4']);
});

test('toArray includes every optional key when set', function (): void {
    $context = new PhotosAddDirectUploadFormPageContext(
        fAddAction: '/admin.php?page=photos_add',
        chunkSize: 500000,
        maxFileSize: 10000000,
        adminPageTitle: 'Upload Photos',
        maxUploadWidth: 4000.0,
        maxUploadHeight: 2600.0,
        maxUploadResolution: 10.0,
        originalResizeMaxwidth: 2000,
        originalResizeMaxheight: 1500,
        formAction: '/admin.php?page=photos_add',
        pwgToken: 'abc123',
        uploadFileTypes: 'jpg, png',
        fileExts: 'jpg,png',
        addToAlbum: 'Holidays',
        selectedCategoryName: null,
        selectedCategory: [5],
        nbAlbums: 3,
        levelOptions: [0 => 'Everybody'],
        levelOptionsSelected: [0],
        setupErrors: ['upload dir is not writable'],
        cacheKeys: ['categories' => '123_4'],
        setupWarnings: ['Exif extension not available, admin should disable exif use'],
        hideWarningsLink: '/admin.php?page=photos_add&amp;hide_warnings=1',
    );

    $result = $context->toArray();

    expect($result['max_upload_width'])->toBe(4000.0)
        ->and($result['max_upload_height'])->toBe(2600.0)
        ->and($result['max_upload_resolution'])->toBe(10.0)
        ->and($result['original_resize_maxwidth'])->toBe(2000)
        ->and($result['original_resize_maxheight'])->toBe(1500)
        ->and($result['ADD_TO_ALBUM'])->toBe('Holidays')
        ->and($result)->not->toHaveKey('selected_category_name')
        ->and($result['setup_warnings'])->toBe(['Exif extension not available, admin should disable exif use'])
        ->and($result['hide_warnings_link'])->toBe('/admin.php?page=photos_add&amp;hide_warnings=1');
});

test('toArray includes selected_category_name when set instead of ADD_TO_ALBUM', function (): void {
    $context = new PhotosAddDirectUploadFormPageContext(
        fAddAction: '/admin.php?page=photos_add',
        chunkSize: 500000,
        maxFileSize: 10000000,
        adminPageTitle: 'Upload Photos',
        maxUploadWidth: null,
        maxUploadHeight: null,
        maxUploadResolution: null,
        originalResizeMaxwidth: null,
        originalResizeMaxheight: null,
        formAction: '/admin.php?page=photos_add',
        pwgToken: 'abc123',
        uploadFileTypes: 'jpg, png',
        fileExts: 'jpg,png',
        addToAlbum: null,
        selectedCategoryName: 'Holidays',
        selectedCategory: [5],
        nbAlbums: 3,
        levelOptions: [0 => 'Everybody'],
        levelOptionsSelected: [0],
        setupErrors: [],
        cacheKeys: [],
        setupWarnings: null,
        hideWarningsLink: null,
    );

    $result = $context->toArray();

    expect($result['selected_category_name'])->toBe('Holidays')
        ->and($result)->not->toHaveKey('ADD_TO_ALBUM');
});
