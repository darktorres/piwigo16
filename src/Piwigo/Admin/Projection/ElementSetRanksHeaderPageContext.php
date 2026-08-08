<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\ElementSetRanksPageRenderer::render()} at
 * template init.
 */
final readonly class ElementSetRanksHeaderPageContext implements TemplatePageContext
{
    /**
     * @param array<string, string> $imageOrderOptions
     */
    public function __construct(
        public string $categoriesNav,
        public string $formAction,
        public string $pwgToken,
        public array $imageOrderOptions,
        public string $imageOrderChoice,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'CATEGORIES_NAV' => $this->categoriesNav,
            'F_ACTION' => $this->formAction,
            'PWG_TOKEN' => $this->pwgToken,
            'image_order_options' => $this->imageOrderOptions,
            'image_order_choice' => $this->imageOrderChoice,
        ];
    }
}
