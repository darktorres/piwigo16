<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Latte\Runtime\Html;
use Piwigo\Core\View;
use Piwigo\Image\DerivativeParams;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `comments.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\CommentsController::__invoke()}. `$categories`/
 * `$categoriesSelected` are `CategorySelectOptions::$options`/`$selected`,
 * unwrapped here rather than kept as a nested object -- `comments.latte`
 * reads both as plain arrays via an `n:foreach`-over-`<option>` loop.
 * `$commentList`
 * stays nullable: the template guards it with `isset()`, and the
 * controller only ever renders a real {@see
 * \Piwigo\Picture\Projection\CommentListView} when there is at least
 * one comment.
 */
#[Template('comments.latte')]
final readonly class CommentsView implements View
{
    /**
     * @param array<int, string> $sinceOptions
     * @param array<string, string> $sortByOptions
     * @param array<string, string> $sortOrderOptions
     * @param array<int|string, int|string> $itemNumberOptions
     * @param array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int} $navbar
     * @param array<int|string, string> $categories
     * @param array<int, mixed> $categoriesSelected
     */
    public function __construct(
        public string $homeUrl,
        public string $fAction,
        public string $fKeyword,
        public string $fAuthor,
        public array $sinceOptions,
        public int $sinceOptionsSelected,
        public array $sortByOptions,
        public string $sortByOptionsSelected,
        public array $sortOrderOptions,
        public string $sortOrderOptionsSelected,
        public array $itemNumberOptions,
        public int|string $itemNumberOptionsSelected,
        public array $navbar,
        public DerivativeParams $commentDerivativeParams,
        public array $categories,
        public array $categoriesSelected,
        public ?Html $commentList,
    ) {}
}
