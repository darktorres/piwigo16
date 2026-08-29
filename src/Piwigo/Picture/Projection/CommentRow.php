<?php

declare(strict_types=1);

namespace Piwigo\Picture\Projection;

use Piwigo\Image\SrcImage;

/**
 * One rendered comment in `comment_list.latte`, built by
 * {@see \Piwigo\Picture\PictureCommentRenderer} for a photo's own
 * comment thread and by
 * {@see \Piwigo\Controller\CommentsController} for the site-wide
 * comments page.
 *
 * Almost every field beyond the first five is a permission or a state,
 * and the template used to ask about each with `isset()`. They are
 * nullable properties now, so the question the template asks is the
 * one the producer answered:
 *
 * - the four action URLs are null when the viewer may not take that
 *   action -- `$validateUrl` doubles as "and this comment is still
 *   awaiting validation";
 * - `$email` is null for anyone but an administrator;
 * - `$inEdit` marks the one comment the page is currently editing, and
 *   `$key`/`$csrfToken`/`$cancelUrl`/`$imageId` are the form fields
 *   that only exist while it is;
 * - `$pictureUrl`/`$srcImage`/`$alt` are the thumbnail and its link,
 *   set only by the comments page, since a photo's own thread is
 *   already on the photo.
 */
final readonly class CommentRow
{
    public function __construct(
        public int|string $id,
        public string $author,
        public string $date,
        public ?string $content,
        public ?string $websiteUrl,
        public ?string $email = null,
        public ?string $deleteUrl = null,
        public ?string $editUrl = null,
        public ?string $cancelUrl = null,
        public ?string $validateUrl = null,
        public bool $inEdit = false,
        public ?string $key = null,
        public ?string $csrfToken = null,
        public int|string|null $imageId = null,
        public ?string $pictureUrl = null,
        public ?SrcImage $srcImage = null,
        public ?string $alt = null,
    ) {}
}
