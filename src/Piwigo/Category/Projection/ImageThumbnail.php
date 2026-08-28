<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Core\Projection\RecentIcon;
use Piwigo\Image\SrcImage;

/**
 * One photo tile in `thumbnails.latte`, built by
 * {@see \Piwigo\Category\CategoryDefaultRenderer::render()}.
 *
 * Was `array_merge($row, [...display keys])` over a raw image row, so the
 * template read a bag of every images column plus seven display keys and
 * used nine of them in total.
 *
 * `$nbComments` and `$nbHits` are nullable because each has its own
 * condition -- a comment-count query that may not have run, and the user's
 * own `show_nb_hits` preference -- which is what the template's two
 * `isset()` checks meant. `$iconTs` likewise follows `index_new_icon`.
 *
 * Three keys the merge produced are gone rather than carried: `DESCRIPTION`,
 * `path_ext` and `file_ext` have no reader in any template, anywhere in
 * `src/`, or in the one event this list is dispatched through (which has no
 * registered handler).
 */
final readonly class ImageThumbnail
{
    public function __construct(
        public int|string $id,
        public string $name,
        public string $url,
        public string $tnAlt,
        public string $tnTitle,
        public SrcImage $srcImage,
        public ?RecentIcon $iconTs = null,
        public ?int $nbComments = null,
        public ?int $nbHits = null,
    ) {}
}
