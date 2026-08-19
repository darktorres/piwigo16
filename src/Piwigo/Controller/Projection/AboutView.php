<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `about.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\AboutController::__invoke()} in place of its former
 * `AboutPageContext`. `$themeAbout` is genuinely optional: the original
 * code only assigned the `THEME_ABOUT` key when the active theme ships
 * its own `about.html` -- `about.latte`'s own body already reads it via
 * `isset($THEME_ABOUT)` / `n:if="isset($themeAbout)"`, which treats an
 * explicit `null` identically to "never assigned", so this stays a
 * plain nullable property rather than needing the old conditional-key
 * `toArray()` shape.
 */
#[Template('about.latte')]
final readonly class AboutView implements View
{
    public function __construct(
        public string $aboutMessage,
        public ?string $themeAbout,
    ) {}
}
