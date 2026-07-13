<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/notification_by_mail.php (page slug "notification_by_mail")
 * -- pure delegate. Its data access already lives entirely behind the
 * admin/include/functions_notification_by_mail.inc.php bridge built on top
 * of Piwigo\Notification\NotificationByMailService (P19, task #350); this
 * page's own remaining ~800 lines are request-orchestration (timeout
 * handling across 3 POST modes, mail template assignment), not extractable
 * data access, the same "most logic already factored, no new AdminService
 * warranted" shape as HistorySubController.
 */
final class NotificationByMailSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/notification_by_mail.php';
    }
}
