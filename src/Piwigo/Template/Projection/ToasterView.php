<?php

declare(strict_types=1);

namespace Piwigo\Template\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template as TemplateAttr;

/**
 * `{templateType}` target for `themes/standard_pages/template/toaster.latte`
 * (docs/PLAN.md's P42-A). Contract-only, same shape as
 * `Piwigo\Controller\Projection\NavigationBarView`'s own precedent: its
 * one real call site (`profile.latte`'s bare `{include 'toaster.latte'}`)
 * passes nothing, and the body itself is 100% static markup plus static
 * `combineScript`/`combineCss` calls -- no real property, and never
 * rendered via `Renderer::render()`.
 */
#[TemplateAttr('themes/standard_pages/template/toaster.latte')]
final readonly class ToasterView implements View {}
