<?php

declare(strict_types=1);

namespace Piwigo\Mail\Projection;

use Latte\Runtime\Html;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `cat_group_info.latte`'s own typed view -- shared by both the
 * `text/html` and `text/plain` variants (identical shape). Constructed
 * by {@see \Piwigo\Mail\MailService::mail()} whenever `$tpl['filename']`
 * is `'cat_group_info'` -- `Admin\AlbumNotificationPageRenderer`'s own
 * "notify a group about an album" feature is the one real caller, via
 * the shared `mailGroup()`/`mail()` pipeline. `$img` stays an array
 * (never null) matching the original's own always-`[]`-or-populated
 * shape -- `cat_group_info.latte`'s own `isset($IMG) &&
 * isset($IMG['link']) && isset($IMG['src'])` guard already treats an
 * empty array and a fully-populated one correctly without a
 * three-state nullable.
 *
 * `#[Template]` points at the `text/html` file for the same reason
 * {@see NotificationAdminView}'s own docblock explains -- this class is
 * rendered via `Template::renderView()` with an explicit bare
 * filename, never through `Renderer::render()`'s own attribute
 * resolution, so the attribute here only serves
 * `ViewTemplateTypeRoundTripTest`/`VariableMapBuilder`.
 *
 * `$cplContent` is Html, not a plain string (P59): admin/webmaster-
 * authored trusted content (AlbumNotificationPageRenderer's own
 * "Album notification" admin form field), same trust boundary as
 * {@see NotificationAdminView::$content}.
 */
#[Template('mail/text/html/cat_group_info.latte')]
final readonly class CatGroupInfoView implements View
{
    /**
     * @param array{link?: string, src?: string} $img
     */
    public function __construct(
        public array $img,
        public string $catName,
        public string $link,
        public Html $cplContent,
    ) {}
}
