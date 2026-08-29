<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\PictureHeaderPageContext;

/**
 * @param array<string, mixed>|null $navFirst
 * @param array<string, mixed>|null $navNext
 * @param list<array<string, mixed>>|null $relatedTags
 */
function picture_header_context(
    ?string $commentImg = null,
    ?string $infoAuthor = null,
    ?array $relatedTags = null,
    ?array $navFirst = null,
    ?array $navNext = null,
    ?string $uPrefetch = null,
): PictureHeaderPageContext {
    return new PictureHeaderPageContext(
        navFirst: $navFirst,
        navPrevious: null,
        navNext: $navNext,
        navLast: null,
        uUp: 'index.php?/category/1',
        commentImg: $commentImg,
        infoAuthor: $infoAuthor,
        infoFile: 'photo.jpg',
        uPrefetch: $uPrefetch,
        relatedTags: $relatedTags,
    );
}

test('a photo with no optional field assigns only the two unconditional keys', function (): void {
    expect(picture_header_context()->toArray())
        ->toBe([
            'U_UP' => 'index.php?/category/1',
            'INFO_FILE' => 'photo.jpg',
        ]);
});

test('the three keys layout.latte builds its meta tags from are each assigned only when present', function (): void {
    // `<meta name="description">` reads COMMENT_IMG, `author` reads
    // INFO_AUTHOR, `keywords` reads related_tags -- an absent key leaves
    // its `n:if="isset(...)"` false and the tag unrendered, which is why
    // these are conditional rather than nullable assigns.
    expect(picture_header_context(
        commentImg: 'A caption',
        infoAuthor: 'Ansel Adams',
        relatedTags: [[
            'name' => 'nature',
        ]],
    )->toArray())
        ->toBe([
            'U_UP' => 'index.php?/category/1',
            'INFO_FILE' => 'photo.jpg',
            'COMMENT_IMG' => 'A caption',
            'INFO_AUTHOR' => 'Ansel Adams',
            'related_tags' => [[
                'name' => 'nature',
            ]],
        ]);
});

test('an author with no caption and no tags still reaches the template', function (): void {
    expect(picture_header_context(infoAuthor: 'Ansel Adams')->toArray())
        ->toBe([
            'U_UP' => 'index.php?/category/1',
            'INFO_FILE' => 'photo.jpg',
            'INFO_AUTHOR' => 'Ansel Adams',
        ]);
});

test('each navigation neighbour is assigned under its own bare key', function (): void {
    expect(picture_header_context(
        navFirst: [
            'U_IMG' => 'index.php?/picture/1',
        ],
        navNext: [
            'U_IMG' => 'index.php?/picture/3',
        ],
        uPrefetch: 'index.php?/picture/3.jpg',
    )->toArray())
        ->toBe([
            'U_UP' => 'index.php?/category/1',
            'INFO_FILE' => 'photo.jpg',
            'first' => [
                'U_IMG' => 'index.php?/picture/1',
            ],
            'next' => [
                'U_IMG' => 'index.php?/picture/3',
            ],
            'U_PREFETCH' => 'index.php?/picture/3.jpg',
        ]);
});
