<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Latte\Runtime\Html;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `group_perm.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\GroupPermPageRenderer::render()}. `$doubleSelect` is
 * this page's own rendered {@see DoubleSelectView} sub-render -- the
 * category-option labels/lists it needs are only ever read by that
 * sub-render, never by `group_perm.latte`'s own body directly.
 */
#[Template('group_perm.latte')]
final readonly class GroupPermView implements View
{
    public function __construct(
        public string $title,
        public string $formAction,
        public string $pwgToken,
        public Html $doubleSelect,
    ) {}
}
