<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Latte\Runtime\Html;
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
final readonly class NotificationByMailView implements View
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
}
