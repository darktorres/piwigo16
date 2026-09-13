<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Latte\Runtime\Html;
use Piwigo\Core\Projection\RecentIcon;
use Piwigo\Image\SrcImage;

/**
 * One album tile in `mainpage_categories.latte`, built by
 * {@see \Piwigo\Category\CategoryCatsRenderer::render()}.
 *
 * It used to be `array_merge($category, [...display keys])` over a
 * `Category` projection that had been `toArray()`'d one loop earlier for
 * exactly that merge -- so the template read a bag carrying every column of
 * the categories table plus nine display keys, and used ten of them.
 *
 * The `toArray()` stays where it is: the merged array is the renderer's own
 * working state for the rollup counts (`nb_images`, `max_date_last`,
 * `is_child_date_last` and the rest, which come from a second query, not
 * from `Category`), and it never reaches a template. What reaches the
 * template is this.
 *
 * `$representative` is the `SrcImage` itself rather than the image row that
 * carried it: the template's two reads are a presence check and
 * `['src_image']`, and nothing else of that row was ever used here.
 *
 * `$iconTs` and `$infoDates` are nullable because their producers are
 * conditional -- `index_new_icon` and `display_fromto` respectively -- which
 * is what the two "key present or absent" checks in the template meant.
 *
 * `$description` is `Html`, not a plain string (P59 Batch 2): its one real
 * producer ({@see \Piwigo\Category\CategoryCatsRenderer::render()}) always
 * runs it through `RenderCategoryLiteralDescription`'s own
 * `strip_tags($desc, '<span><p><a><br><b><i><small><big><strong><em>')`
 * pass -- unconditionally, unlike the earlier, permission-gated
 * `RenderCategoryDescription` its value already went through -- so this
 * field is genuinely safe pre-formed HTML regardless of the
 * `allowHtmlDescriptions` setting.
 *
 * `$captionNbImages` is `Html` too (P59 Batch 4): its one real producer
 * ({@see \Piwigo\Category\CategoryService::getDisplayImagesCount()}) joins
 * `Lang::plural()`/`Lang::t()`'s own always-translator-authored strings
 * with a hardcoded `'<br>'` literal, never user data.
 *
 * `$coi` (P29.6) is the representative image's own center-of-interest --
 * available on the same row `$representative` itself is built from
 * ({@see \Piwigo\Category\CategoryCatsRenderer::render()}'s own
 * `$representativeInfos['coi']`), but never previously threaded through:
 * no core renderer crops a category thumbnail around it. A theme's own
 * `IndexCategoryThumbnailsRendered` handler is the one real consumer
 * (the `modus` theme's own album-thumbnail crop math, P29.6 Phase 8).
 * See `MenubarSpecialRow::$kind`'s own docblock for the same "lands ahead
 * of its consumer on purpose" rationale.
 *
 * `$representativeFileExt` (gdThumb port): the representative image's own
 * real, original file extension -- `$representativeInfos['file']` is
 * already in scope at this class's one real producer (an `Image::
 * toArray()` row, same shape `ImageThumbnail::$fileExt`'s own docblock
 * describes for the image-grid case), so this is another pure additive
 * read, not a new query. Empty string when there is no representative.
 *
 * `$countImages` (gdThumb port): the same raw `int` `$captionNbImages`
 * is itself formatted from (`CategoryCatsRenderer::render()`'s own
 * `$catCountImages` local, already computed a few lines above the
 * `CategoryThumbnail` construction it feeds) -- needed as a plain
 * number, not `$captionNbImages`'s own translated "N photos"-shaped
 * `Html` string, for a numeric badge.
 */
final readonly class CategoryThumbnail
{
    public function __construct(
        public int|string $id,
        public string $name,
        public string $url,
        public string $tnAlt,
        public Html $captionNbImages,
        public ?SrcImage $representative,
        public ?Html $description,
        public ?RecentIcon $iconTs = null,
        public ?string $infoDates = null,
        // No real reader yet -- see this class's own docblock.
        // @phpstan-ignore shipmonk.deadProperty.neverRead
        public ?string $coi = null,
        public string $representativeFileExt = '',
        public int $countImages = 0,
    ) {}
}
