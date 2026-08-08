<?php

declare(strict_types=1);

namespace Piwigo\Picture\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned unconditionally by
 * {@see \Piwigo\Picture\PictureCommentRenderer::render()}. `comments`
 * is seeded empty here -- the real rows (when `$nbComments > 0`) are
 * appended on top afterward via `Template::append('comments', ...)`,
 * a separate call this context deliberately doesn't touch.
 */
final readonly class PictureCommentListPageContext implements TemplatePageContext
{
    /**
     * @param array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int} $navbar
     */
    public function __construct(
        public int $commentCount,
        public array $navbar,
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
            'comments' => [],
        ];
    }
}
