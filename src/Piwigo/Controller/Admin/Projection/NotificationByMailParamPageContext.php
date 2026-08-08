<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\NotificationByMailSubController::handle()}'s
 * own `case 'param':` display branch.
 */
final readonly class NotificationByMailParamPageContext implements TemplatePageContext
{
    public function __construct(
        public bool $sendHtmlMail,
        public string $sendMailAs,
        public bool $sendDetailedContent,
        public string $complementaryMailContent,
        public bool $sendRecentPostDates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'param' => [
                'SEND_HTML_MAIL' => $this->sendHtmlMail,
                'SEND_MAIL_AS' => $this->sendMailAs,
                'SEND_DETAILED_CONTENT' => $this->sendDetailedContent,
                'COMPLEMENTARY_MAIL_CONTENT' => $this->complementaryMailContent,
                'SEND_RECENT_POST_DATES' => $this->sendRecentPostDates,
            ],
        ];
    }
}
