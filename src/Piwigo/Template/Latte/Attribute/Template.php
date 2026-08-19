<?php

declare(strict_types=1);

namespace Piwigo\Template\Latte\Attribute;

use Attribute;

/**
 * `#[Template('index.latte')]` on a `View` implementation -- names the
 * `.latte` file `Renderer::render()` resolves that class against. One
 * class, one template, declared at the class instead of threaded through
 * a controller call site.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Template
{
    public function __construct(
        public string $file
    ) {}
}
