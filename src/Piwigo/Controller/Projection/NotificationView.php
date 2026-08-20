<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Core\HasHeadLinks;
use Piwigo\Core\HeadLink;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `notification.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\NotificationController::__invoke()}.
 * `$feedImageOnlyTitle`/`$feedTitle` are the 2 translated `<link>`
 * titles the template's own `{do htmlHead(...)}` call previously
 * translated inline -- the controller resolves them via its own `Lang`
 * service, same as every other pre-translated View property.
 */
#[Template('notification.latte')]
final readonly class NotificationView implements View, HasHeadLinks
{
    public function __construct(
        public string $feedUrl,
        public string $feedImageOnlyUrl,
        public string $feedImageOnlyTitle,
        public string $feedTitle,
    ) {}

    /**
     * `notification.latte`'s own unconditional `{do htmlHead(...)}`
     * (2 RSS-discovery `<link>` tags) -- docs/PLAN.md's P42-B.
     */
    #[Override]
    public function headLinks(): array
    {
        return [
            new HeadLink(rel: 'alternate', href: $this->feedImageOnlyUrl, type: 'application/rss+xml', title: $this->feedImageOnlyTitle),
            new HeadLink(rel: 'alternate', href: $this->feedUrl, type: 'application/rss+xml', title: $this->feedTitle),
        ];
    }
}
