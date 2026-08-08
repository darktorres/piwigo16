<?php

declare(strict_types=1);

namespace Piwigo\Picture\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The 'comment_add' template variable assigned by
 * {@see \Piwigo\Picture\PictureCommentRenderer::render()} -- a growing
 * associative bag of form-field values, not a fixed structural shape
 * worth minting individual properties for here.
 */
final readonly class PictureCommentAddPageContext implements TemplatePageContext
{
    /**
     * @param array<string, mixed> $commentAdd
     */
    public function __construct(
        public array $commentAdd,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'comment_add' => $this->commentAdd,
        ];
    }
}
