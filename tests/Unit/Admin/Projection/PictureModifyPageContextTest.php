<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\PictureModifyPageContext;

test('toArray flattens every fixed property, and omits save_success/U_COI/STORAGE_CATEGORY/U_JUMPTO when null', function (): void {
    $context = new PictureModifyPageContext(
        saveSuccess: null,
        tagSelection: [1 => ['name' => 'sunset', 'id' => '1']],
        uDownload: '/action.php?id=5&part=e&pwg_token=abc',
        uSync: '/admin.php?page=photo-5-properties&sync_metadata=1&pwg_token=abc',
        uDelete: '/admin.php?page=photo-5-properties&delete=1&pwg_token=abc',
        uHistory: '/admin.php?page=history&filter_image_id=5',
        uActivity: '/admin.php?page=user_activity&photo=5',
        path: 'galleries/sunset.jpg',
        tnSrc: '/i/sunset-md.jpg',
        fileSrc: '/i/sunset-lg.jpg',
        name: 'Sunset',
        title: 'Sunset',
        dimensions: '1920 * 1080',
        format: 0,
        filesize: '512 KB',
        registrationDate: 'August 8, 2026',
        author: 'jane',
        dateCreation: '2026-08-08',
        description: 'A nice sunset',
        fAction: '/admin.php',
        introVars: ['file' => 'sunset.jpg'],
        uCoi: null,
        levelOptions: [0 => 'Everybody'],
        levelOptionsSelected: [0],
        storageCategory: null,
        relatedCategories: ['5' => ['name' => 'Holidays', 'unlinkable' => true]],
        relatedCategoriesIds: ['5'],
        uJumpto: null,
        associatedAlbums: [5],
        representedAlbums: [5],
        storageAlbum: '5',
        cacheKeys: ['tags' => 'x', 'categories' => 'y'],
        pwgToken: 'abc123',
    );

    $result = $context->toArray();

    expect($result)->not->toHaveKeys(['save_success', 'U_COI', 'STORAGE_CATEGORY', 'U_JUMPTO'])
        ->and($result['NAME'])->toBe('Sunset')
        ->and($result['PATH'])->toBe('galleries/sunset.jpg')
        ->and($result['DATE_CREATION'])->toBe('2026-08-08')
        ->and($result['STORAGE_ALBUM'])->toBe('5');
});

test('toArray includes save_success/U_COI/STORAGE_CATEGORY/U_JUMPTO when set', function (): void {
    $context = new PictureModifyPageContext(
        saveSuccess: 'Photo informations updated',
        tagSelection: [],
        uDownload: '/action.php?id=5&part=e&pwg_token=abc',
        uSync: '/admin.php?page=photo-5-properties&sync_metadata=1&pwg_token=abc',
        uDelete: '/admin.php?page=photo-5-properties&delete=1&pwg_token=abc',
        uHistory: '/admin.php?page=history&filter_image_id=5',
        uActivity: '/admin.php?page=user_activity&photo=5',
        path: null,
        tnSrc: '/i/sunset-md.jpg',
        fileSrc: '/i/sunset-lg.jpg',
        name: 'Sunset',
        title: 'Sunset',
        dimensions: '1920 * 1080',
        format: 1,
        filesize: '512 KB',
        registrationDate: 'August 8, 2026',
        author: 'jane',
        dateCreation: null,
        description: 'A nice sunset',
        fAction: '/admin.php',
        introVars: [],
        uCoi: '/admin.php?page=picture_coi&image_id=5',
        levelOptions: [],
        levelOptionsSelected: [],
        storageCategory: 'Holidays',
        relatedCategories: [],
        relatedCategoriesIds: [],
        uJumpto: '/index.php?/picture/5',
        associatedAlbums: [],
        representedAlbums: [],
        storageAlbum: null,
        cacheKeys: [],
        pwgToken: 'abc123',
    );

    $result = $context->toArray();

    expect($result['save_success'])->toBe('Photo informations updated')
        ->and($result['U_COI'])->toBe('/admin.php?page=picture_coi&image_id=5')
        ->and($result['STORAGE_CATEGORY'])->toBe('Holidays')
        ->and($result['U_JUMPTO'])->toBe('/index.php?/picture/5');
});
