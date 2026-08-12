<?php

declare(strict_types=1);

namespace Piwigo\Picture\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Picture\PictureCommentRenderer::render()} once its own
 * `$nbComments > 0` branch (if any) finishes -- `comments` is always
 * included (even empty) since `comment_list.latte`'s own
 * `{foreach from=$comments}` has no guard around it.
 */
final readonly class PictureCommentListPageContext implements TemplatePageContext
{
    /**
     * @param array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int} $navbar
     * @param list<array<string, mixed>> $comments
     */
    public function __construct(
        public int $commentCount,
        public array $navbar,
        public array $comments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'COMMENT_COUNT' => $this->commentCount,
            'navbar' => $this->navbar,
            'comments' => $this->comments,
        ];
    }
}
