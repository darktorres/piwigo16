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
 * Two keys the merge produced are still gone rather than carried:
 * `path_ext` and `file_ext` have no reader in any template, anywhere in
 * `src/`, or in the one event this list is dispatched through (which has no
 * registered handler). `DESCRIPTION` was dropped for the same reason, but
 * a real reader now exists (`bootstrap_darkroom`'s own `{block thumbName}`
 * override, P61 closing audit -- legacy's `thumbnail_desc`/
 * `thumbnail_cat_desc` settings choose the photo's description over its
 * name in the thumbnail caption) -- restored as `$description`, already
 * computed unconditionally either way (`CategoryDefaultRenderer`'s own
 * `renderElementDescription()` call already builds this same string for
 * `$tnTitle`), so this is a pure additive read, no new work done per row.
 *
 * `$ratingScore` (gdThumb port) is the same story again: the raw row
 * `CategoryDefaultRenderer::render()` builds every `ImageThumbnail` from
 * already carries `rating_score` (a native DBAL `float|null`) -- it's
 * already read there today, but only inside the `Section::BestRated`
 * branch, for an unrelated purpose (prefixing the admin thumbnail name
 * label with `"(4.5) "`). A pure additive read of data already in hand,
 * not a new query -- no reader existed for the public, per-thumbnail
 * caption case this field is for until now.
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
        public ?string $description = null,
        public ?float $ratingScore = null,
    ) {}
}
