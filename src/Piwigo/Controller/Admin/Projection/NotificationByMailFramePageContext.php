<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\NotificationByMailSubController::handle()}.
 * `$saveSuccess` and `$repostSubmitName` are genuinely optional -- the
 * original code only ever assigns those 2 template keys under their own
 * runtime condition, omitted here (not present as a null value) to match
 * that exact original behavior. Deferred to the very end of handle()
 * (after the page-mode switch) since neither `double_select.tpl` nor
 * `notification_by_mail.latte` reads any of these before that point
 * (confirmed by direct read).
 */
final readonly class NotificationByMailFramePageContext implements TemplatePageContext
{
    public function __construct(
        public ?string $saveSuccess,
        public string $pwgToken,
        public string $helpUrl,
        public string $fAction,
        public ?string $repostSubmitName,
        public string $adminPageTitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'CSRF_TOKEN' => $this->pwgToken,
            'U_HELP' => $this->helpUrl,
            'F_ACTION' => $this->fAction,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
        ];

        if ($this->saveSuccess !== null) {
            $result['save_success'] = $this->saveSuccess;
        }

        if ($this->repostSubmitName !== null) {
            $result['REPOST_SUBMIT_NAME'] = $this->repostSubmitName;
        }

        return $result;
    }
}
