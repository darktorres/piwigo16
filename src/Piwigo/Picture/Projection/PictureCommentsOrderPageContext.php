<?php

declare(strict_types=1);

namespace Piwigo\Picture\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Picture\PictureCommentRenderer::render()}, only when
 * the picture has at least one comment.
 */
final readonly class PictureCommentsOrderPageContext implements TemplatePageContext
{
    public function __construct(
        public string $orderUrl,
        public string $orderTitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'COMMENTS_ORDER_URL' => $this->orderUrl,
            'COMMENTS_ORDER_TITLE' => $this->orderTitle,
        ];
    }
}
