<?php

declare(strict_types=1);

namespace Piwigo\Mail\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `notification_by_mail.latte`'s own typed view -- shared by both the
 * `text/html` and `text/plain` variants (identical shape). Constructed
 * by {@see \Piwigo\Mail\NotificationByMailSender}, which builds one of
 * two mutually exclusive shapes per call: the subscribe/unsubscribe
 * branch (`$subscribeByAdmin`/`$subscribeByHimself`/
 * `$unsubscribeByAdmin`/`$unsubscribeByHimself`, exactly one `true`,
 * plus `$gotoGalleryTitle`/`$gotoGalleryUrl`) or the news branch
 * (`$contentNewElementsBetween`/`$contentNewElementsSingle`/
 * `$globalNewLines`/`$customMailContent`/`$recentPosts`, plus its own
 * `$gotoGalleryTitle`/`$gotoGalleryUrl`) -- merges what were three
 * separate ambient contexts (`NbmMailContentPageContext`,
 * `NbmSubscribeActionMailContext`, `NbmNewsMailContext`) into one
 * class, since nothing else ever assigns into this template's own
 * `Template::$vars` between them the way the old ambient mechanism
 * allowed. The subscribe/unsubscribe branch leaves the news-only
 * fields at their neutral defaults (`null`/`[]`) and vice versa.
 * `$gotoGalleryTitle`/`$gotoGalleryUrl` are the pair both branches
 * always supply, so neither is nullable: they were `?string` only
 * because the template guarded them with `!empty()`, which reads as a
 * nullability question but was really asking whether the gallery has a
 * title to link to (P58-B2).
 *
 * `#[Template]` points at the `text/html` file for the same reason
 * {@see NotificationAdminView}'s own docblock explains -- this class is
 * rendered via `Template::renderView()` with an explicit bare
 * filename, never through `Renderer::render()`'s own attribute
 * resolution, so the attribute here only serves
 * `ViewTemplateTypeRoundTripTest`/`VariableMapBuilder`.
 */
#[Template('mail/text/html/notification_by_mail.latte')]
final readonly class NotificationByMailView implements View
{
    /**
     * @param array{DATE_BETWEEN_1: string, DATE_BETWEEN_2: string}|null $contentNewElementsBetween
     * @param array{DATE_SINGLE: string}|null $contentNewElementsSingle
     * @param array<int, string>|null $globalNewLines
     * @param list<array{TITLE: string, HTML_DATA: string}> $recentPosts
     */
    public function __construct(
        public string $username,
        public ?string $sendAsName,
        public string $unsubscribeLink,
        public string $subscribeLink,
        public ?string $contactEmail,
        public bool $subscribeByAdmin,
        public bool $subscribeByHimself,
        public bool $unsubscribeByAdmin,
        public bool $unsubscribeByHimself,
        public ?array $contentNewElementsBetween,
        public ?array $contentNewElementsSingle,
        public ?array $globalNewLines,
        public ?string $customMailContent,
        public string $gotoGalleryTitle,
        public string $gotoGalleryUrl,
        public array $recentPosts,
    ) {}
}
