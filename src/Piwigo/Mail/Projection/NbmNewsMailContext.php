<?php

declare(strict_types=1);

namespace Piwigo\Mail\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The mail template variable set assigned by
 * {@see \Piwigo\Mail\NotificationByMailSender::sendMailNotifications()}'s
 * own "existing news for this user" branch. `$contentNewElementsBetween`/
 * `$contentNewElementsSingle` are mutually exclusive (whether the user
 * has a prior `last_send`); `$globalNewLines`/`$customMailContent` are
 * independently optional -- the original code only ever assigns those
 * template keys under their own runtime condition, omitted here (not
 * present as a null value) to match that exact original behavior.
 * `$recentPosts` is always included (even empty) since
 * `notification_by_mail.latte` reads it with `{if !empty($recent_posts)}`,
 * not `isset()`.
 */
final readonly class NbmNewsMailContext implements TemplatePageContext
{
    /**
     * @param array{DATE_BETWEEN_1: string, DATE_BETWEEN_2: string}|null $contentNewElementsBetween
     * @param array{DATE_SINGLE: string}|null $contentNewElementsSingle
     * @param array<int, string>|null $globalNewLines
     * @param list<array{TITLE: string, HTML_DATA: string}> $recentPosts
     */
    public function __construct(
        public ?array $contentNewElementsBetween,
        public ?array $contentNewElementsSingle,
        public ?array $globalNewLines,
        public ?string $customMailContent,
        public string $galleryTitle,
        public string $galleryUrl,
        public ?string $sendAsName,
        public array $recentPosts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'recent_posts' => $this->recentPosts,
        ];

        if ($this->contentNewElementsBetween !== null) {
            $result['content_new_elements_between'] = $this->contentNewElementsBetween;
        }

        if ($this->contentNewElementsSingle !== null) {
            $result['content_new_elements_single'] = $this->contentNewElementsSingle;
        }

        if ($this->globalNewLines !== null) {
            $result['global_new_lines'] = $this->globalNewLines;
        }

        if ($this->customMailContent !== null) {
            $result['custom_mail_content'] = $this->customMailContent;
        }

        $result['GOTO_GALLERY_TITLE'] = $this->galleryTitle;
        $result['GOTO_GALLERY_URL'] = $this->galleryUrl;
        $result['SEND_AS_NAME'] = $this->sendAsName;

        return $result;
    }
}
