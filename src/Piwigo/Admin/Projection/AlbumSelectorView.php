<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template as TemplateAttr;

/**
 * `{templateType}` target for
 * `themes/admin/default/template/include/album_selector.inc.latte`
 * (docs/PLAN.md's P42-A). Contract-only, same shape as
 * `Piwigo\Controller\Projection\NavigationBarView`'s own precedent --
 * never rendered via `Renderer::render()`, only `{include}`d, across 7
 * real parents (`photos_add_direct.latte`, `cat_modify.latte`,
 * `batch_manager_unit.latte`, `batch_manager_global.latte`,
 * `picture_modify.latte`, `search_filters.inc.latte`, and
 * `batch_manager_filter.inc.latte` itself -- the reason this file
 * converts before or with `batch_manager_filter.inc.latte`, not after).
 *
 * `$load_mode` is genuinely optional -- the template's own
 * `{if empty($load_mode)}{var $load_mode = 'footer'}{/if}` default
 * applies at all 7 real call sites, none of which pass it explicitly.
 */
#[TemplateAttr('include/album_selector.inc.latte')]
final readonly class AlbumSelectorView implements View
{
    public function __construct(
        public ?string $load_mode = null,
    ) {}
}
