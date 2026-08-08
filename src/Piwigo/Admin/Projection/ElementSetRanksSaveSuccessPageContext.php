<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The 'save_success' template variable assigned by
 * {@see \Piwigo\Admin\ElementSetRanksPageRenderer::render()}, only when
 * the form was submitted.
 */
final readonly class ElementSetRanksSaveSuccessPageContext implements TemplatePageContext
{
    public function __construct(
        public string $message,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'save_success' => $this->message,
        ];
    }
}
