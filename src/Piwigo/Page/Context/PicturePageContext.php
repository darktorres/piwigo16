<?php

declare(strict_types=1);

namespace Piwigo\Page\Context;

/**
 * Page context for the single-image page (PictureController).
 *
 * Consumed by #23 Wave 0 Latte partials:
 *   picture_comment.inc.php   → _partials/picture-comment.latte
 *   picture_metadata.inc.php  → _partials/picture-metadata.latte
 *   picture_rate.inc.php      → _partials/picture-rate.latte
 *   no_photo_yet.inc.php      → _partials/no-photo-yet.latte
 */
final readonly class PicturePageContext
{
    /**
     * @param array<string,mixed>       $picture            Image DB row (id, file, path, width, height, …).
     * @param list<array<string,mixed>> $relatedCategories  All categories this image belongs to.
     * @param list<int>                 $items              Sibling image IDs for prev/next navigation.
     * @param array<string,mixed>|null  $category           Context category when browsing from an album.
     */
    public function __construct(
        public array      $picture,
        public array      $relatedCategories,
        public array      $items,
        public array|null $category,
        public string     $commentAction,
        public string     $urlSelf,
    ) {}
}
