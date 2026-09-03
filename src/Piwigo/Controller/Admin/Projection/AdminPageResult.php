<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Latte\Runtime\Html;

/**
 * P43-F: what an admin page renderer hands back instead of directly
 * assigning {@see AdminContentPageContext} itself. `Bootstrap\AdminDispatcher::dispatch()`
 * is the one real caller that turns this into the ambient
 * AdminContentPageContext the "admin.latte" shell reads. `$pageTitle`/
 * `$helpUrl` are optional for the exact same reason AdminContentPageContext's
 * own fields are: most pages keep AdminShell's own default title and have
 * no per-page help link.
 *
 * `$pageTitle` accepts `string|Html` (P59 correction): the vast
 * majority of real callers pass a plain `Lang::t()` translation, safe
 * to auto-escape as-is, but 2 (`GroupListPageRenderer`'s own
 * `"Groups <span class=\"badge-number\">…</span>"`, and
 * `PhotoSubController`'s own `"Edit photo <span
 * class=\"image-id\">…</span>"`, which constructs {@see
 * AdminContentPageContext} directly rather than through this class)
 * hand-build real markup and must wrap it in `Html` explicitly --
 * `admin.latte`'s own bare `{$ADMIN_PAGE_TITLE}` print already handles
 * either shape correctly at runtime, per value.
 */
final readonly class AdminPageResult
{
    public function __construct(
        public Html $content,
        public string|Html|null $pageTitle = null,
        public ?string $helpUrl = null,
    ) {}
}
