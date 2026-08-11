<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\ElementSetRanksPageRenderer::render()} at
 * template init. `$thumbnails` is always included (even empty) since
 * `element_set_ranks.tpl` reads it with `{if !empty($thumbnails)}`, not
 * `isset()`. `$imageOrder` is always exactly 3 elements (the original
 * code always runs its own fixed 3-iteration loop, unconditionally) --
 * `element_set_ranks.tpl`'s own `{foreach from=$image_order}` has no
 * guard at all, so this must always be present.
 */
final readonly class ElementSetRanksHeaderPageContext implements TemplatePageContext
{
    /**
     * @param array<string, string> $imageOrderOptions
     * @param list<array<string, mixed>> $thumbnails
     * @param list<string> $imageOrder
     */
    public function __construct(
        public string $categoriesNav,
        public string $formAction,
        public string $pwgToken,
        public array $imageOrderOptions,
        public string $imageOrderChoice,
        public array $thumbnails,
        public array $imageOrder,
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
            'CSRF_TOKEN' => $this->pwgToken,
            'image_order_options' => $this->imageOrderOptions,
            'image_order_choice' => $this->imageOrderChoice,
            'thumbnails' => $this->thumbnails,
            'image_order' => $this->imageOrder,
        ];
    }
}
