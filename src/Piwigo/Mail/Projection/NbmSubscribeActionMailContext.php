<?php

declare(strict_types=1);

namespace Piwigo\Mail\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The mail template variable set assigned by
 * {@see \Piwigo\Mail\NotificationByMailSender::doSubscribeUnsubscribeNotificationByMail()}.
 * `$sectionActionBy` is the ONE of 4 literal keys the original computed
 * dynamically (`(un)subscribe_by_` . `admin`/`himself`) and assigned
 * `true` -- fully enumerable from this single call site (2 independent
 * booleans), so kept as a real literal-union type rather than a loose
 * string.
 */
final readonly class NbmSubscribeActionMailContext implements TemplatePageContext
{
    /**
     * @param 'subscribe_by_admin'|'subscribe_by_himself'|'unsubscribe_by_admin'|'unsubscribe_by_himself' $sectionActionBy
     */
    public function __construct(
        public string $sectionActionBy,
        public string $galleryTitle,
        public string $galleryUrl,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            $this->sectionActionBy => true,
            'GOTO_GALLERY_TITLE' => $this->galleryTitle,
            'GOTO_GALLERY_URL' => $this->galleryUrl,
        ];
    }
}
