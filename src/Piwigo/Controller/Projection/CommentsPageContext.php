<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Category\Projection\CategorySelectOptions;
use Piwigo\Core\TemplatePageContext;
use Piwigo\Image\DerivativeParams;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\CommentsController::__invoke()}. `$comments`
 * is always included (even empty) since `comment_list.tpl`'s own
 * `{foreach from=$comments}` has no guard around it. `$categoriesOptions`
 * is {@see \Piwigo\Category\CategoryService}'s own
 * `categories`/`categories_selected` pair.
 */
final readonly class CommentsPageContext implements TemplatePageContext
{
    /**
     * @param array<int, string> $sinceOptions
     * @param array<string, string> $sortByOptions
     * @param array<string, string> $sortOrderOptions
     * @param array<int|string, int|string> $itemNumberOptions
     * @param array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int} $navbar
     * @param list<array<string, mixed>> $comments
     */
    public function __construct(
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
        public array $comments,
        public CategorySelectOptions $categoriesOptions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'F_ACTION' => $this->fAction,
            'F_KEYWORD' => $this->fKeyword,
            'F_AUTHOR' => $this->fAuthor,
            'since_options' => $this->sinceOptions,
            'since_options_selected' => $this->sinceOptionsSelected,
            'sort_by_options' => $this->sortByOptions,
            'sort_by_options_selected' => $this->sortByOptionsSelected,
            'sort_order_options' => $this->sortOrderOptions,
            'sort_order_options_selected' => $this->sortOrderOptionsSelected,
            'item_number_options' => $this->itemNumberOptions,
            'item_number_options_selected' => $this->itemNumberOptionsSelected,
            'navbar' => $this->navbar,
            'comment_derivative_params' => $this->commentDerivativeParams,
            'comments' => $this->comments,
            'categories' => $this->categoriesOptions->options,
            'categories_selected' => $this->categoriesOptions->selected,
        ];
    }
}
