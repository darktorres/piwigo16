<?php

declare(strict_types=1);

namespace Piwigo\Mail\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The mail template variable set assigned by
 * {@see \Piwigo\Mail\NotificationByMailSender::assignVarsNbmMailContent()},
 * a shared per-user helper called from both
 * `doSubscribeUnsubscribeNotificationByMail()` and
 * `sendMailNotifications()`, always before either method's own
 * additional assigns for that same user.
 */
final readonly class NbmMailContentPageContext implements TemplatePageContext
{
    public function __construct(
        public string $username,
        public ?string $sendAsName,
        public string $unsubscribeLink,
        public string $subscribeLink,
        public ?string $contactEmail,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'USERNAME' => $this->username,
            'SEND_AS_NAME' => $this->sendAsName,
            'UNSUBSCRIBE_LINK' => $this->unsubscribeLink,
            'SUBSCRIBE_LINK' => $this->subscribeLink,
            'CONTACT_EMAIL' => $this->contactEmail,
        ];
    }
}
