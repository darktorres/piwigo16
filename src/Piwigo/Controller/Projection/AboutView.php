<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `about.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\AboutController::__invoke()}. `$themeAbout` is set
 * only when the active theme ships its own `about.html` --
 * `about.latte`'s own body guards it with `n:if="isset($themeAbout)"`,
 * which treats an explicit `null` identically to "never assigned".
 */
#[Template('about.latte')]
final readonly class AboutView implements View
{
    public function __construct(
        public string $aboutMessage,
        public ?string $themeAbout,
    ) {}
}
