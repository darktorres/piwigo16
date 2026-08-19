<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Latte\Runtime\Html;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `cat_options.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\CatOptionsPageRenderer::render()}. `$doubleSelect` is
 * this page's own rendered {@see DoubleSelectView} sub-render -- the
 * category-option labels/lists it needs are only ever read by that
 * sub-render, never by `cat_options.latte`'s own body directly.
 */
#[Template('cat_options.latte')]
final readonly class CatOptionsView implements View
{
    public function __construct(
        public string $formAction,
        public string $section,
        public string $pwgToken,
        public Html $doubleSelect,
    ) {}
}
