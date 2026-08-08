<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;
use Piwigo\Menu\DisplayBlock;

/**
 * The 'blocks' template variable assigned by
 * {@see \Piwigo\Menu\BlockManager::apply()}.
 */
final readonly class MenubarBlocksPageContext implements TemplatePageContext
{
    /**
     * @param array<int|string, DisplayBlock> $blocks
     */
    public function __construct(
        public array $blocks,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'blocks' => $this->blocks,
        ];
    }
}
