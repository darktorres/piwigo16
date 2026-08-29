<?php

declare(strict_types=1);

namespace Piwigo\Picture\Projection;

/**
 * The "add a comment" form on the picture page, built by {@see
 * \Piwigo\Picture\PictureCommentRenderer::render()} only when the current
 * user may actually post -- `PictureCommentsResult::$commentAdd` is null
 * otherwise, and `picture.latte` guards the whole block on that.
 *
 * The three `$show*` flags are the form's own field visibility, not
 * permissions: a classic user's author and email are already known, so
 * those inputs are omitted and the server fills them in.
 *
 * `$content`/`$author`/`$websiteUrl`/`$email` are empty on a first render
 * and carry the rejected submission back on a failed post, HTML-escaped
 * at construction because the template echoes them into `value=`
 * attributes.
 */
final readonly class CommentAddForm
{
    public function __construct(
        public string $formAction,
        public string $key,
        public string $content,
        public bool $showAuthor,
        public bool $authorMandatory,
        public string $author,
        public string $websiteUrl,
        public bool $showEmail,
        public bool $emailMandatory,
        public string $email,
        public bool $showWebsite,
    ) {}
}
