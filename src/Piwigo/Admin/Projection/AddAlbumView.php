<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template as TemplateAttr;

/**
 * `{templateType}` target for
 * `themes/admin/default/template/include/add_album.inc.latte`
 * (docs/PLAN.md's P42-A). Contract-only, same shape as
 * `Piwigo\Controller\Projection\NavigationBarView`'s own precedent --
 * never rendered via `Renderer::render()`, only `{include}`d.
 *
 * `$load_mode` is genuinely optional -- the template's own
 * `{if empty($load_mode)}{var $load_mode = 'footer'}{/if}` default
 * applies whenever a real call site omits it. `$themeconf` (the file's
 * only other real read) isn't declared here -- it's a true cross-cutting
 * ambient `ContextVariableExtractor` already resolves for every real
 * template, `{templateType}` or not.
 */
#[TemplateAttr('include/add_album.inc.latte')]
final readonly class AddAlbumView implements View
{
    public function __construct(
        public ?string $load_mode = null,
    ) {}
}
