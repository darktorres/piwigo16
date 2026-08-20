<?php

declare(strict_types=1);

namespace Piwigo\Mail\Projection;

use Latte\Runtime\Html;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `notification_admin.latte`'s own typed view -- shared by both the
 * `text/html` and `text/plain` variants (identical shape, see
 * `#[Template]`'s own note below), constructed by
 * {@see \Piwigo\Mail\MailService::mail()} whenever `$tpl['filename']`
 * is `'notification_admin'` (`MailService::mailNotificationAdmins()`
 * and `Admin\Extensions\CoreUpdateService::notifyAdminsOfNewVersion()`
 * are the two real callers, via the shared `mail()`/`mailAdmins()`
 * pipeline). `$technical` stays nullable to match `$sendTechnicalDetails`'s
 * own optionality -- `CoreUpdateService` never passes it at all.
 *
 * `#[Template]` points at the `text/html` file specifically because
 * `Template::renderView()` (not `Renderer::render()`) is what actually
 * renders this -- `MailService` calls it directly against its own
 * per-format `Template` instance with an explicit bare filename, so
 * this attribute is read only by `ViewTemplateTypeRoundTripTest`/
 * `VariableMapBuilder`'s static analysis, never at runtime. The
 * `text/plain` sibling file declares the same `{templateType}` back to
 * this class; nothing requires the round trip to hold in both
 * directions, only that this class's own declared file resolves and
 * matches -- see `Piwigo\Mail\Projection\CatGroupInfoView`'s own
 * docblock for the same reasoning applied a second time.
 */
#[Template('mail/text/html/notification_admin.latte')]
final readonly class NotificationAdminView implements View
{
    /**
     * @param array{username: string, ip: string, user_agent: string}|null $technical
     */
    public function __construct(
        public Html $content,
        public ?array $technical,
    ) {}
}
