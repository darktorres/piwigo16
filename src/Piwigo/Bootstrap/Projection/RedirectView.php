<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `redirect.latte`'s own typed view, constructed by {@see
 * \Piwigo\Bootstrap\RedirectService::redirectHtml()}.
 *
 * `$refreshUrl` is the destination the meta-refresh points at, and the
 * href of the "click here" fallback link. The template used to read it off
 * the ambient `$page_refresh['U_REFRESH']`, whose whole array is nullable
 * -- it is assigned only when a refresh is actually scheduled. That is
 * never in doubt here: `redirectHtml()` hands the same URL to
 * `PageHeaderRenderer::prepareContext()` two lines before rendering this.
 *
 * `$redirectMsg` is Html, not string (P59): every real caller of
 * `redirectHtml()`'s own `$msg` param -- directly, or through
 * `HtmlService::pageForbidden()`/`badRequest()`/`pageNotFound()`, which
 * concatenate it into a hand-built `<div><h1>...` string -- passes
 * either a hardcoded literal or a `Lang::t()` translation, never
 * attacker- or user-supplied data.
 */
#[Template('redirect.latte')]
final readonly class RedirectView implements View, HasPageAssets
{
    public function __construct(
        public Html $redirectMsg,
        public string $refreshUrl,
    ) {}

    /**
     * `redirect.latte`'s own unconditional `{do combineCss(...)}`
     * (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('themes/default/css/pages/redirect.css', id: 'redirect'),
        ];
    }
}
