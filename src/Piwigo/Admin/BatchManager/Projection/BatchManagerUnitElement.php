<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager\Projection;

/**
 * One photo row of the batch manager's unit tab, built by
 * {@see \Piwigo\Admin\BatchManagerUnitPageRenderer::render()}.
 *
 * Was `array_merge($row, [...33 display keys])` over
 * `ImageRepository::findBatchManagerUnitRows()`, which is declared
 * `list<array<string, mixed>>` -- a raw DBAL row with no projection to
 * compose, so this is a flat row VO rather than one wrapping something.
 *
 * `$id` replaces two keys. The merge wrote `'ID' => $row_id` beside the raw
 * row's own `id`, both from `$row['id']`, and the template read whichever
 * came to hand -- `ID` for the markup ids and badges, `id` for the
 * datepicker and tag field names.
 *
 * Eight of the merged keys are gone, having no reader in this page's
 * template, in `BatchManagerUnitView::exposedPageData()`, or in
 * `batchManagerUnit.ts`: LEGEND, LEVEL, is_svg, TITLE, REGISTRATION_DATE,
 * tag_selection (a duplicate of TAGS), U_DELETE and U_SYNC.
 *
 * `$relatedCategoryIds` is the one field the template never touches:
 * `exposedPageData()` decodes it per row to build the album-selector's
 * `all_related_categories_ids` payload. The producer coerces
 * `json_encode()`'s `false` to `''`, which is what the View's own reader
 * did before this was typed.
 *
 * `$isWide` is the old `format` flag, which was an int the template used
 * as a boolean. Its name is the honest one: the producer computes
 * `width >= height`, and the CSS classes it selects are named the other
 * way round (`is-portrait` styles a wide image, `is-landscape` a tall
 * one). Those class names are wrong in both this page and
 * `picture_modify.latte`, and their rules are right, so the rendering has
 * always been correct; renaming them is a CSS change, not this one.
 */
final readonly class BatchManagerUnitElement
{
    /**
     * @param array<int, array{name: mixed, id: string}> $tags
     * @param array<int, array{name: string, unlinkable: bool}> $relatedCategories
     */
    public function __construct(
        public int|string $id,
        public string $tnSrc,
        public string $fileSrc,
        public string $uEdit,
        public string $name,
        public string $author,
        public string $description,
        public ?string $dateCreation,
        public array $tags,
        public string $dimensions,
        public bool $isWide,
        public string $filesize,
        public string $ext,
        public string $postDate,
        public string $age,
        public string $addedBy,
        public string $stats,
        public string $file,
        public array $relatedCategories,
        public string $relatedCategoryIds,
        public ?string $uJumpto,
        public string $uDownload,
        public string $uHistory,
        public string $uActivity,
        public string $path,
        public ?int $levelSelected,
    ) {}
}
