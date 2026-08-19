<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `site_manager.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\SiteManagerSubController::handle()}.
 * `$sites` is always included (even empty) since `site_manager.latte`
 * reads it with `{if !empty($sites)}`, not `isset()`.
 */
#[Template('site_manager.latte')]
final readonly class SiteManagerView implements View
{
    /**
     * @param list<array<string, mixed>> $sites
     */
    public function __construct(
        public string $formAction,
        public string $csrfToken,
        public array $sites,
    ) {}
}
