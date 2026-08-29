<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Piwigo\Contribution\SiteLink;

/**
 * One row of `site_manager.latte`'s `$sites` list, built by
 * {@see \Piwigo\Controller\Admin\SiteManagerSubController::handle()}.
 * `$pluginLinks` is whatever
 * {@see \Piwigo\Controller\Admin\Event\GetAdminsSiteLinks} collected --
 * a plugin-extensible list, typed as the contribution it always was.
 */
final readonly class SiteRow
{
    /**
     * @param list<SiteLink> $pluginLinks
     */
    public function __construct(
        public string $name,
        public string $type,
        public int $categories,
        public int $images,
        public string $uSynchronize,
        public ?string $uDelete,
        public array $pluginLinks,
    ) {}
}
