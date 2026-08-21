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
 */
final readonly class AdminPageResult
{
    public function __construct(
        public Html $content,
        public ?string $pageTitle = null,
        public ?string $helpUrl = null,
    ) {}
}
