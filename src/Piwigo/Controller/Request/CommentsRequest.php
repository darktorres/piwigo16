<?php

declare(strict_types=1);

namespace Piwigo\Controller\Request;

use Piwigo\Common\Enum\SortOrder;
use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for CommentsController::__invoke()
 * (comments.php) -- P26/SEC-40 Request DTO.
 *
 * `authorFilter`/`keywordFilter` split from `authorDisplay`/`keywordDisplay`:
 * the original re-read `author`/`keyword` a second time for the form's
 * own F_AUTHOR/F_KEYWORD echo, with a DIFFERENT presence check than the
 * one guarding the SQL filter clause (`is_string(...)` alone for
 * display, vs. `is_string(...) && !== '' && !== '0'` for the filter) --
 * a request of `author=0` filters nothing but still echoes "0" back
 * into the form field, so collapsing both reads into one field would
 * lose that distinction.
 *
 * `sortBy`/`sortOrder` resolve directly to their final, default-applied
 * value: the original's own valid-value check was `isset($sort_by[...])`/
 * `isset($sort_order[...])` against 2 small hardcoded local arrays
 * (`['date' => ..., 'image_id' => ...]` / `['DESC' => ..., 'ASC' =>
 * ...]`), not a config- or DB-driven set, so hardcoding the same 2
 * literal key lists here carries no real coupling risk.
 *
 * `itemsNumber` needs `$commentsPageNbComments` (a config value) as its
 * own "no GET override" default -- passed in explicitly rather than
 * read from config inside the DTO, matching every other config-gated
 * field elsewhere in this migration.
 *
 * `action`/`actionCommentId` fold the original's own delete/validate/edit
 * 3-way loop (`foreach (['delete', 'validate', 'edit'] as $loop_action)`)
 * into 2 fields: whichever of the 3 GET keys is present wins (matching
 * the original's `break` on first match), each individually validated
 * against `ValidationPattern::ID`. `actionCommentId` is reused for BOTH
 * of the original's 2 separate `$_GET['edit']` reads inside the
 * `edit`-action branch (the update-comment call's own `comment_id` key,
 * and the later `$edit_comment` "which comment is in edit mode" marker)
 * -- both read the exact same validated GET value, just one cast
 * in a downstream is_numeric() check the other didn't bother with.
 */
final readonly class CommentsRequest
{
    private function __construct(
        public ?string $since,
        public string $sortBy,
        public string $sortOrder,
        public int|string $itemsNumber,
        public ?string $catDisplay,
        public ?string $catId,
        public ?string $authorFilter,
        public ?string $authorDisplay,
        public ?int $commentId,
        public ?string $keywordFilter,
        public ?string $keywordDisplay,
        public ?string $action,
        public ?int $actionCommentId,
        public int $start,
        public ?string $content,
        public string $key,
        public ?string $imageId,
        public ?string $websiteUrl,
    ) {}

    public static function fromGlobals(int $commentsPageNbComments, InputValidator $inputValidator): self
    {
        return self::fromArrays($_GET, $_POST, $commentsPageNbComments, $inputValidator);
    }

    /**
     * @param array<int|string, mixed> $get
     * @param array<int|string, mixed> $post
     */
    public static function fromArrays(array $get, array $post, int $commentsPageNbComments, InputValidator $inputValidator): self
    {
        $since_raw = $get['since'] ?? null;
        $since = (is_string($since_raw) && $since_raw !== '' && $since_raw !== '0') ? $since_raw : null;

        $sort_by_raw = $get['sort_by'] ?? null;
        $sort_by = (is_string($sort_by_raw) && in_array($sort_by_raw, ['date', 'image_id'], true)) ? $sort_by_raw : 'date';

        $sort_order_raw = $get['sort_order'] ?? null;
        $sort_order = (is_string($sort_order_raw) && in_array($sort_order_raw, [SortOrder::Desc->value, SortOrder::Asc->value], true)) ? $sort_order_raw : SortOrder::Desc->value;

        // No (int) cast on the GET-provided branch: the original kept
        // $_GET['items_number'] as its native string in $page['items_number']
        // and used it straight in the LIMIT clause and the option-list
        // lookup, so a numeric-string GET value must stay a string here too.
        $items_number_value = $commentsPageNbComments;
        if (isset($get['items_number'])) {
            $items_number_raw = $get['items_number'];
            $items_number_value = (is_int($items_number_raw) || is_string($items_number_raw)) ? $items_number_raw : 10;
        }
        if (! is_numeric($items_number_value) and $items_number_value !== 'all') {
            $items_number_value = 10;
        }
        $items_number = $items_number_value;

        $cat_raw = $get['cat'] ?? null;
        $cat_display = is_string($cat_raw) ? $cat_raw : null;

        $cat_id = null;
        if (isset($get['cat']) and ! (is_numeric($cat_raw) and (int) $cat_raw === 0)) {
            $inputValidator
                ->validate('cat', $get, false, ValidationPattern::ID);
            $cat_id = is_string($get['cat']) ? $get['cat'] : '0';
        }

        $author_raw = $get['author'] ?? null;
        $author_filter = (is_string($author_raw) && $author_raw !== '' && $author_raw !== '0') ? $author_raw : null;
        $author_display = is_string($author_raw) ? $author_raw : null;

        $comment_id_raw = $get['comment_id'] ?? null;
        $comment_id = null;
        if (is_string($comment_id_raw) && $comment_id_raw !== '' && $comment_id_raw !== '0') {
            $inputValidator
                ->validate('comment_id', $get, false, ValidationPattern::ID);
            assert(is_numeric($comment_id_raw));
            $comment_id = (int) $comment_id_raw;
        }

        $keyword_raw = $get['keyword'] ?? null;
        $keyword_filter = (is_string($keyword_raw) && $keyword_raw !== '' && $keyword_raw !== '0') ? $keyword_raw : null;
        $keyword_display = is_string($keyword_raw) ? $keyword_raw : null;

        $action = null;
        $action_comment_id = null;
        foreach (['delete', 'validate', 'edit'] as $loop_action) {
            if (isset($get[$loop_action])) {
                $inputValidator
                    ->validate($loop_action, $get, false, ValidationPattern::ID);
                $action_id_raw = $get[$loop_action] ?? null;
                assert(is_numeric($action_id_raw));
                $action_comment_id = (int) $action_id_raw;
                $action = $loop_action;
                break;
            }
        }

        $start_raw = $get['start'] ?? null;
        $start = (isset($get['start']) and is_scalar($start_raw)) ? intval($start_raw) : 0;

        $content_raw = $post['content'] ?? null;
        $content = (is_string($content_raw) && $content_raw !== '' && $content_raw !== '0') ? $content_raw : null;

        $post_key_raw = $post['key'] ?? null;
        $key = is_string($post_key_raw) ? $post_key_raw : '';

        $image_id_raw = $post['image_id'] ?? null;
        $image_id = is_string($image_id_raw) ? $image_id_raw : null;

        $website_url_raw = $post['website_url'] ?? null;
        $website_url = is_string($website_url_raw) ? $website_url_raw : null;

        return new self(
            $since,
            $sort_by,
            $sort_order,
            $items_number,
            $cat_display,
            $cat_id,
            $author_filter,
            $author_display,
            $comment_id,
            $keyword_filter,
            $keyword_display,
            $action,
            $action_comment_id,
            $start,
            $content,
            $key,
            $image_id,
            $website_url,
        );
    }
}
