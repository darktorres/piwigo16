<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * `MENUBAR` alone -- {@see \Piwigo\Menu\BlockManager::apply()} renders
 * {@see MenubarView} via `Renderer::render()` and writes the result into
 * `Template::$vars` this way, same one-field-wrapper shape as {@see
 * \Piwigo\Controller\Projection\ThumbnailsHtmlPageContext} --
 * `assignContext()` stays the sole way anything writes into the
 * template, even for an already-rendered `Html` value.
 */
final readonly class MenubarHtmlPageContext implements TemplatePageContext
{
    public function __construct(
        public Html $menubar,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'MENUBAR' => $this->menubar,
        ];
    }
}
