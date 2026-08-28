<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Menu\RegisteredBlock;

/**
 * One row of `menubar.latte`'s `$blocks` position-editor list, built by
 * {@see \Piwigo\Admin\MenubarPageRenderer::render()}. `reg` was already a
 * real {@see \Piwigo\Menu\RegisteredBlock} object at the template layer
 * (`$block['reg']->getId()`/`getName()`/`getOwner()`) -- only the PHP-side
 * `mixed` typing was stale, not the actual shape.
 */
final readonly class MenubarBlockConfigRow
{
    public function __construct(
        public int|float $pos,
        public RegisteredBlock $reg,
    ) {}
}
