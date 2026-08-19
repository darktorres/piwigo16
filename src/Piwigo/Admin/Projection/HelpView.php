<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `help.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\HelpPageRenderer::render()}.
 */
#[Template('help.latte')]
final readonly class HelpView implements View
{
    public function __construct(
        public string $helpContent,
        public string $helpSectionTitle,
    ) {}
}
