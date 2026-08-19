<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `menubar.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\MenubarPageRenderer::render()}. `$blocks` is always
 * included -- the loop that builds it runs unconditionally, and
 * `menubar.latte`'s own `{foreach}` has no guard around it either.
 * `$saveSuccess` is genuinely optional, only set once the menubar
 * order was just saved.
 */
#[Template('menubar.latte')]
final readonly class MenubarView implements View
{
    /**
     * @param list<array{pos: int|float, reg: mixed}> $blocks
     */
    public function __construct(
        public string $formAction,
        public int $isWebmaster,
        public array $blocks,
        public ?string $saveSuccess,
    ) {}
}
