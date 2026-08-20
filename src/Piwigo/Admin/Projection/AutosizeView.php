<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template as TemplateAttr;

/**
 * `{templateType}` target for
 * `themes/admin/default/template/include/autosize.inc.latte`
 * (docs/PLAN.md's P42-A). Contract-only, same shape as
 * `Piwigo\Controller\Projection\NavigationBarView`'s own precedent --
 * never rendered via `Renderer::render()`, only `{include}`d, and 100%
 * static `combineScript` calls, no real property. The
 * `themes/default/template/include/autosize.inc.latte` counterpart this
 * file used to share a basename with had zero real callers anywhere in
 * the app and was deleted outright rather than converted.
 */
#[TemplateAttr('include/autosize.inc.latte')]
final readonly class AutosizeView implements View {}
