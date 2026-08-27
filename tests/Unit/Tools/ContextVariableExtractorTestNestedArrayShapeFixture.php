<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Tools;

use Piwigo\Core\TemplatePageContext;

/**
 * Throwaway fixture, not a real production class -- CategoryCatsNavbarPageContext
 * (the last real TemplatePageContext with a nested array-shape docblock
 * param) was retyped to a real Navbar VO param, leaving no remaining real
 * class shaped this way to exercise the "maps ... with a nested
 * array-shape docblock" test.
 */
final readonly class ContextVariableExtractorTestNestedArrayShapeFixture implements TemplatePageContext
{
    /**
     * @param array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int} $catsNavbar
     */
    public function __construct(
        public array $catsNavbar,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'cats_navbar' => $this->catsNavbar,
        ];
    }
}
