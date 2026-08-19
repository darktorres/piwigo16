<?php

declare(strict_types=1);

namespace Piwigo\Picture\Projection;

use Override;
use Piwigo\Core\Projection\Navbar;
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
     * @param list<array<string, mixed>> $comments
     */
    public function __construct(
        public int $commentCount,
        public Navbar $navbar,
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
            'navbar' => $this->navbar->toArray(),
            'comments' => $this->comments,
        ];
    }
}
