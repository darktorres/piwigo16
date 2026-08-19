<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `element_set_ranks.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\ElementSetRanksPageRenderer::render()}. No
 * `$categoriesNav` field -- the template's own body never references
 * it. `$thumbnails` is always included (even empty) since the template
 * reads it with `{if !empty($thumbnails)}`, not `isset()`. `$imageOrder`
 * is always exactly 3 elements -- render()'s own fixed 3-iteration loop
 * runs unconditionally, and the template's own `{foreach $imageOrder as
 * $order}` has no guard at all.
 */
#[Template('element_set_ranks.latte')]
final readonly class ElementSetRanksView implements View
{
    /**
     * @param array<string, string> $imageOrderOptions
     * @param list<array<string, mixed>> $thumbnails
     * @param list<string> $imageOrder
     */
    public function __construct(
        public string $formAction,
        public string $csrfToken,
        public array $imageOrderOptions,
        public string $imageOrderChoice,
        public array $thumbnails,
        public array $imageOrder,
        public ?string $saveSuccess,
    ) {}
}
