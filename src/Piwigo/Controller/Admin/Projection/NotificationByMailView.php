<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `notification_by_mail.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\NotificationByMailSubController::handle()}.
 * Exactly one of `$param`/`$subscribeActive`/`$send` is ever populated
 * per request -- the page has 3 mutually exclusive tabs
 * (`param`/`subscribe`/`send`), gated by `AccessControl` on the first
 * two. `$doubleSelect` is only set when `$subscribeActive` is true.
 */
#[Template('notification_by_mail.latte')]
final readonly class NotificationByMailView implements View, HasPageAssets
{
    /**
     * @param array{SEND_HTML_MAIL: bool, SEND_MAIL_AS: string, SEND_DETAILED_CONTENT: bool, COMPLEMENTARY_MAIL_CONTENT: string, SEND_RECENT_POST_DATES: bool}|null $param
     * @param array{users: list<array{ID: string, CHECKED: string, USERNAME: string, EMAIL: string, LAST_SEND: ?string}>, CUSTOMIZE_MAIL_CONTENT: string}|null $send
     */
    public function __construct(
        public ?array $param,
        public bool $subscribeActive,
        public ?Html $doubleSelect,
        public ?array $send,
        public ?string $authKeyDuration,
        public ?string $saveSuccess,
        public string $csrfToken,
        public string $formAction,
        public ?string $repostSubmitName,
    ) {}

    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            // Real per-page bundle entry (docs/PLAN.md's P48) -- folds
            // autosize.ts's code in via a direct import instead of a
            // separate script tag (autosize.ts has 3 real registrant
            // pages, so a plain import isn't safe here -- Design §4).
            // autosize.ts's own `jquery.autogrow` dependency is gone too
            // -- autogrow is a native port now (P49-B group 1).
            AssetContribution::script('notification_by_mail_page', 'themes/admin/default/js/pages/notification_by_mail.ts', loadMode: LoadMode::Footer),
            AssetContribution::script('notification_by_mail', 'themes/admin/default/js/notificationByMail.ts', loadMode: LoadMode::Footer),
        ];
    }
}
