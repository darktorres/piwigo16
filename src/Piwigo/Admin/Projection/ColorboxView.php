<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template as TemplateAttr;

/**
 * `{templateType}` target for
 * `themes/admin/default/template/include/colorbox.inc.latte`
 * (docs/PLAN.md's P42-A). Contract-only, same shape as
 * `Piwigo\Controller\Projection\NavigationBarView`'s own precedent --
 * never rendered via `Renderer::render()`, only `{include}`d. The
 * `themes/default/template/include/colorbox.inc.latte` counterpart this
 * file used to share a basename with had zero real callers anywhere in
 * the app and was deleted outright rather than converted.
 *
 * `$load_mode` is genuinely optional -- the template's own
 * `{if empty($load_mode)}{var $load_mode = 'footer'}{/if}` default
 * applies whenever a real call site omits it (most do).
 */
#[TemplateAttr('include/colorbox.inc.latte')]
final readonly class ColorboxView implements View
{
    public function __construct(
        public ?string $load_mode = null,
    ) {}
}
