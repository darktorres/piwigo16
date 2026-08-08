<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\PicturePageContext;

/**
 * @param array<string, mixed>|null $navFirst
 * @param array<string, mixed>|null $navPrevious
 * @param array<string, mixed>|null $navNext
 * @param array<string, mixed>|null $navLast
 * @param array<string, mixed>|null $navCurrent
 * @param array<string, string>|null $slideshowNav
 * @param array{IS_FAVORITE: bool, U_FAVORITE: string}|null $favorite
 */
function makePicturePageContextForTest(
    ?array $navFirst = null,
    ?array $navPrevious = null,
    ?array $navNext = null,
    ?array $navLast = null,
    ?array $navCurrent = null,
    ?string $uSlideshowStop = null,
    ?array $slideshowNav = null,
    ?string $uSlideshowStart = null,
    ?string $uMetadata = null,
    ?string $uSetAsRepresentative = null,
    ?string $uPhotoAdmin = null,
    ?string $uCaddie = null,
    ?array $favorite = null,
    ?string $commentImg = null,
    ?string $infoAuthor = null,
    ?string $infoCreationDate = null,
    ?string $infoDimensions = null,
    ?string $infoFilesize = null,
    ?int $pdfViewerFilesizeThreshold = null,
    int|false|null $pdfNbPages = null,
    ?string $uPrefetch = null,
): PicturePageContext {
    return new PicturePageContext(
        navFirst: $navFirst,
        navPrevious: $navPrevious,
        navNext: $navNext,
        navLast: $navLast,
        navCurrent: $navCurrent,
        uSlideshowStop: $uSlideshowStop,
        slideshowNav: $slideshowNav,
        uSlideshowStart: $uSlideshowStart,
        sectionTitle: 'Holidays',
        photo: '3/10',
        isHome: false,
        levelSeparator: ' &gt; ',
        uUp: '/index.php?/category/1',
        displayNavButtons: true,
        displayNavThumb: true,
        uMetadata: $uMetadata,
        uSetAsRepresentative: $uSetAsRepresentative,
        uPhotoAdmin: $uPhotoAdmin,
        uCaddie: $uCaddie,
        favorite: $favorite,
        commentImg: $commentImg,
        infoAuthor: $infoAuthor,
        infoCreationDate: $infoCreationDate,
        infoPostedDate: '<a href="#">August 5, 2026</a>',
        infoDimensions: $infoDimensions,
        infoFilesize: $infoFilesize,
        infoVisits: '42',
        infoFile: 'photo.jpg',
        displayInfo: ['file' => true],
        pdfViewerFilesizeThreshold: $pdfViewerFilesizeThreshold,
        pdfNbPages: $pdfNbPages,
        elementContent: '',
        uPrefetch: $uPrefetch,
        uCanonical: '/picture.php?/5',
    );
}

test('toArray flattens every fixed property, and omits every optional key when null', function (): void {
    $result = makePicturePageContextForTest()->toArray();

    expect($result)->not->toHaveKeys(['first', 'previous', 'next', 'last', 'current', 'U_SLIDESHOW_STOP', 'slideshow', 'U_SLIDESHOW_START', 'U_METADATA', 'U_SET_AS_REPRESENTATIVE', 'U_PHOTO_ADMIN', 'U_CADDIE', 'favorite', 'COMMENT_IMG', 'INFO_AUTHOR', 'INFO_CREATION_DATE', 'INFO_DIMENSIONS', 'INFO_FILESIZE', 'PDF_VIEWER_FILESIZE_THRESHOLD', 'PDF_NB_PAGES', 'U_PREFETCH'])
        ->and($result['SECTION_TITLE'])->toBe('Holidays')
        ->and($result['INFO_VISITS'])->toBe('42')
        ->and($result['display_info'])->toBe(['file' => true]);
});

test('toArray includes navCurrent under "current" when set', function (): void {
    $result = makePicturePageContextForTest(navCurrent: ['id' => '5', 'url' => '/picture.php?id=5'])->toArray();

    expect($result['current'])->toBe(['id' => '5', 'url' => '/picture.php?id=5']);
});

test('toArray includes U_SLIDESHOW_STOP and slideshow together', function (): void {
    $result = makePicturePageContextForTest(
        uSlideshowStop: '/picture.php?id=5',
        slideshowNav: ['U_STOP_REPEAT' => '/picture.php?id=5&slideshow=abc'],
    )->toArray();

    expect($result['U_SLIDESHOW_STOP'])->toBe('/picture.php?id=5')
        ->and($result['slideshow'])->toBe(['U_STOP_REPEAT' => '/picture.php?id=5&slideshow=abc'])
        ->and($result)->not->toHaveKey('U_SLIDESHOW_START');
});

test('toArray includes U_SLIDESHOW_START alone', function (): void {
    $result = makePicturePageContextForTest(uSlideshowStart: '/picture.php?id=5&slideshow=')->toArray();

    expect($result['U_SLIDESHOW_START'])->toBe('/picture.php?id=5&slideshow=')
        ->and($result)->not->toHaveKey('U_SLIDESHOW_STOP')
        ->and($result)->not->toHaveKey('slideshow');
});

test('toArray includes PDF_VIEWER_FILESIZE_THRESHOLD and PDF_NB_PAGES together, including a false page count', function (): void {
    $result = makePicturePageContextForTest(
        pdfViewerFilesizeThreshold: 2048,
        pdfNbPages: false,
    )->toArray();

    expect($result['PDF_VIEWER_FILESIZE_THRESHOLD'])->toBe(2048)
        ->and($result['PDF_NB_PAGES'])->toBeFalse();
});

test('toArray includes every other optional key when set', function (): void {
    $result = makePicturePageContextForTest(
        uMetadata: '/picture.php?/5&metadata',
        uSetAsRepresentative: '/picture.php?/5&action=set_as_representative',
        uPhotoAdmin: '/admin.php?page=photo-5',
        uCaddie: '/picture.php?/5&action=add_to_caddie',
        favorite: ['IS_FAVORITE' => true, 'U_FAVORITE' => '/picture.php?/5&action=remove_from_favorites'],
        commentImg: '<p>A description</p>',
        infoAuthor: 'admin',
        infoCreationDate: '<a href="#">July 1, 2026</a>',
        infoDimensions: '1920*1080',
        infoFilesize: '512 Kb',
        uPrefetch: '/i/derivative.jpg',
    )->toArray();

    expect($result['U_METADATA'])->toBe('/picture.php?/5&metadata')
        ->and($result['U_SET_AS_REPRESENTATIVE'])->toBe('/picture.php?/5&action=set_as_representative')
        ->and($result['U_PHOTO_ADMIN'])->toBe('/admin.php?page=photo-5')
        ->and($result['U_CADDIE'])->toBe('/picture.php?/5&action=add_to_caddie')
        ->and($result['favorite'])->toBe(['IS_FAVORITE' => true, 'U_FAVORITE' => '/picture.php?/5&action=remove_from_favorites'])
        ->and($result['COMMENT_IMG'])->toBe('<p>A description</p>')
        ->and($result['INFO_AUTHOR'])->toBe('admin')
        ->and($result['INFO_CREATION_DATE'])->toBe('<a href="#">July 1, 2026</a>')
        ->and($result['INFO_DIMENSIONS'])->toBe('1920*1080')
        ->and($result['INFO_FILESIZE'])->toBe('512 Kb')
        ->and($result['U_PREFETCH'])->toBe('/i/derivative.jpg');
});
