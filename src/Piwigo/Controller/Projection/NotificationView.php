<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `notification.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\NotificationController::__invoke()}.
 */
#[Template('notification.latte')]
final readonly class NotificationView implements View
{
    public function __construct(
        public string $feedUrl,
        public string $feedImageOnlyUrl,
    ) {}
}
