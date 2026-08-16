<?php

declare(strict_types=1);

namespace Piwigo\Notification\Event;

use Piwigo\Notification\Projection\UserMailNotification;

/**
 * Typed event for the legacy `nbm_render_user_customize_mail_content`
 * filter. No handler is registered for it anywhere today. Lives under
 * `Piwigo\Notification\Event\`, not `Piwigo\Event\Mail\`, since it
 * carries a real `Piwigo\Notification\Projection\UserMailNotification`
 * instance -- deptrac's L0Data layer may depend on nothing. The
 * reference types the equivalent `$nbmUser` property as a loose
 * `array<string, mixed>`; this branch's own real call site
 * (`NotificationByMailSender::sendMailNotifications()`) already has it
 * as the typed projection object, so that's what this carries instead.
 * Mutable on `$customizeMailContent`; `$nbmUser` stays context.
 */
final class NbmRenderUserCustomizeMailContent
{
    public function __construct(
        public string $customizeMailContent,
        public readonly UserMailNotification $nbmUser,
    ) {}
}
