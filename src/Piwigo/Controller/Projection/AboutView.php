<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Latte\Runtime\Html;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `about.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\AboutController::__invoke()}. `$themeAbout` is set
 * only when the active theme ships its own `about.html` --
 * `about.latte`'s own body guards it with `n:if="isset($themeAbout)"`,
 * which treats an explicit `null` identically to "never assigned".
 *
 * Both are Html, not string (P59 correction): `Lang::load()`'s own
 * static `about.html` asset file content -- bundled, developer/
 * translator-authored, never user data -- but genuinely contains real
 * markup (`<p>` paragraphs), not just escaped text. An earlier pass
 * dropped these prints' own `|noescape` without also retyping the
 * fields, corrupting the content into literal `&lt;p&gt;` text (caught
 * by `about.html`'s own golden-HTML snapshot).
 */
#[Template('about.latte')]
final readonly class AboutView implements View
{
    public function __construct(
        public Html $aboutMessage,
        public ?Html $themeAbout,
    ) {}
}
