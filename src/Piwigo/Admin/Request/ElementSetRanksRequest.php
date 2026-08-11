<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

/**
 * Validated `$_GET`/`$_POST` shape for ElementSetRanksPageRenderer::render()
 * (the "sort_order" tab of the "album" page slug).
 *
 * `isCatIdValid`/`catId` split the original's `isset(...) or ! is_numeric(...)`
 * guard from its own unconditional `(int) $_GET['cat_id']` cast that
 * follows it -- the original's fatal-signal call in that guard
 * (ErrorCollector::recordFatal(), see HtmlService::fatalError()'s own
 * docblock) doesn't actually halt; only an explicit throw/return does
 * that, so execution falls through to the cast either way, same as here.
 *
 * `imageOrderChoice` folds the original's two-part check
 * (`! in_array(..., [null, false, 0, '0', '', []], true) && in_array(...,
 * $image_order_choices, true)`) into one strict `in_array()` against the
 * 3 known choices -- the first half was already redundant: no member of
 * `['default', 'rank', 'user_define']` can ever equal one of those empty
 * sentinels.
 *
 * `rankOfImage` reproduces the original's `array_filter(...,
 * is_numeric(...))` + `asort(..., SORT_NUMERIC)` pipeline verbatim, since
 * it's a self-contained transform of the raw POST array with no
 * dependency on renderer-local state. `imageOrderFields` stays a raw
 * array -- its own per-index `is_string(...)`/`in_array(...,
 * array_keys($sort_fields))` validation depends on the renderer's own
 * `$sort_fields` local, so it stays at the call site.
 */
final readonly class ElementSetRanksRequest
{
    /**
     * @param array<int|string, mixed> $rankOfImage
     * @param array<int|string, mixed> $imageOrderFields
     */
    private function __construct(
        public bool $isCatIdValid,
        public int $catId,
        public bool $isSubmitted,
        public array $rankOfImage,
        public string $imageOrderChoice,
        public array $imageOrderFields,
        public bool $isImageOrderSubcats,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArrays($_GET, $_POST);
    }

    /**
     * @param array<int|string, mixed> $get
     * @param array<int|string, mixed> $post
     */
    public static function fromArrays(array $get, array $post): self
    {
        $cat_id_raw = $get['cat_id'] ?? null;
        $is_cat_id_valid = isset($get['cat_id']) && is_numeric($cat_id_raw);
        $cat_id = $is_cat_id_valid ? (int) $cat_id_raw : 0;

        $rank_of_image_raw = $post['rank_of_image'] ?? null;
        $rank_of_image = [];
        if (is_array($rank_of_image_raw)) {
            $rank_of_image = array_filter($rank_of_image_raw, is_numeric(...));
            asort($rank_of_image, SORT_NUMERIC);
        }

        $image_order_choice_raw = $post['image_order_choice'] ?? null;
        // 'default' in this list is unobservable on its own: the fallback
        // value below is that same literal 'default', so whether an
        // explicit 'default' input matches via in_array() or falls through
        // to the ternary's else branch, the result is identical either
        // way.
        $image_order_choice = in_array($image_order_choice_raw, ['default', 'rank', 'user_define'], true)
            ? $image_order_choice_raw
            : 'default';

        $image_order_fields_raw = $post['image_order'] ?? null;
        $image_order_fields = is_array($image_order_fields_raw) ? $image_order_fields_raw : [];

        return new self(
            $is_cat_id_valid,
            $cat_id,
            isset($post['submit']),
            $rank_of_image,
            $image_order_choice,
            $image_order_fields,
            isset($post['image_order_subcats']),
        );
    }
}
