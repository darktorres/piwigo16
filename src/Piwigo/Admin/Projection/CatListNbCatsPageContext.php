<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The 'nb_cats' template variable assigned by
 * {@see \Piwigo\Admin\CatListPageRenderer::render()}.
 */
final readonly class CatListNbCatsPageContext implements TemplatePageContext
{
    public function __construct(
        public int $nbCats,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'nb_cats' => $this->nbCats,
        ];
    }
}
