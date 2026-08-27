<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Tools;

use Piwigo\Core\TemplatePageContext;
use Piwigo\Menu\DisplayBlock;

/**
 * Throwaway fixture, not a real production class -- P40 converted every
 * real TemplatePageContext with a DisplayBlock-typed array param
 * (MenubarBlocksPageContext) to a typed View, leaving no remaining real
 * class shaped this way to exercise the "FQCN-expands use-imported
 * classes in docblock types" test.
 *
 * `DisplayBlock` below must stay a short name resolved through the `use`
 * import above: spelling it as an FQCN is what the test is checking the
 * extractor does, so inlining it here would make the assertion vacuous.
 */
final readonly class ContextVariableExtractorTestDisplayBlocksFixture implements TemplatePageContext
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
    #[\Override]
    public function toArray(): array
    {
        return [
            'blocks' => $this->blocks,
        ];
    }
}
