<?php

declare(strict_types=1);

namespace Piwigo\Controller\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for PictureController::__invoke()
 * (replaces picture.php) -- P27/SEC-40 Request DTO.
 *
 * `commentToEdit`/`commentToDelete`/`commentToValidate`'s own
 * `InputValidator::validate()` calls each only run when `action` matches
 * that exact case (`edit_comment`/`delete_comment`/`validate_comment`),
 * matching the original's own switch-case gating exactly -- a malformed
 * `comment_to_edit` GET param stays silently ignored for any other
 * action, same as before.
 *
 * `websiteUrl` stays `mixed` -- the original read it with zero
 * normalization (`@$_POST['website_url']`, passed straight through to
 * `CommentService::updateComment()`); this only drops the `@`
 * suppression in favor of `?? null`, same effect.
 *
 * `contentPresent` is the raw, unmutated `isset($_POST['content'])`
 * snapshot -- the original later `unset($_POST['content'])` inside the
 * `edit_comment` case specifically so a *later*, separate
 * `isset($_POST['content'])` re-check (gating whether to increment the
 * hit counter) would see it as consumed; the controller now tracks that
 * with a local variable seeded from this snapshot instead of mutating
 * the superglobal.
 */
final readonly class PictureRequest
{
    private function __construct(
        public bool $metadataPresent,
        public ?string $action,
        public ?string $rate,
        public ?string $commentToEdit,
        public ?string $content,
        public string $postKey,
        public mixed $websiteUrl,
        public ?string $commentToDelete,
        public ?string $commentToValidate,
        public bool $contentPresent,
        public bool $slideshowPresent,
        public ?string $slideshow,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArrays($_GET, $_POST);
    }

    /**
     * @param array<int|string, mixed> $get
     * @param array<int|string, mixed> $post
     */
    public static function fromArrays(array $get, array $post): self
    {
        $action_raw = $get['action'] ?? null;
        $action = is_string($action_raw) ? $action_raw : null;

        $rate_raw = $post['rate'] ?? null;
        $rate = is_string($rate_raw) ? $rate_raw : null;

        $comment_to_edit = null;
        if ($action === 'edit_comment') {
            InputValidator::createStatic()
                ->validate('comment_to_edit', $get, false, ValidationPattern::ID);
            $comment_to_edit_raw = $get['comment_to_edit'] ?? null;
            $comment_to_edit = is_string($comment_to_edit_raw) ? $comment_to_edit_raw : null;
        }

        $comment_to_delete = null;
        if ($action === 'delete_comment') {
            InputValidator::createStatic()
                ->validate('comment_to_delete', $get, false, ValidationPattern::ID);
            $comment_to_delete_raw = $get['comment_to_delete'] ?? null;
            $comment_to_delete = is_string($comment_to_delete_raw) ? $comment_to_delete_raw : null;
        }

        $comment_to_validate = null;
        if ($action === 'validate_comment') {
            InputValidator::createStatic()
                ->validate('comment_to_validate', $get, false, ValidationPattern::ID);
            $comment_to_validate_raw = $get['comment_to_validate'] ?? null;
            $comment_to_validate = is_string($comment_to_validate_raw) ? $comment_to_validate_raw : null;
        }

        $content_raw = $post['content'] ?? null;
        $content = is_string($content_raw) ? $content_raw : null;

        $post_key_raw = $post['key'] ?? null;
        $post_key = is_string($post_key_raw) ? $post_key_raw : '';

        $slideshow_present = isset($get['slideshow']);
        $slideshow_raw = $get['slideshow'] ?? null;
        $slideshow = is_string($slideshow_raw) ? $slideshow_raw : null;

        return new self(
            isset($get['metadata']),
            $action,
            $rate,
            $comment_to_edit,
            $content,
            $post_key,
            $post['website_url'] ?? null,
            $comment_to_delete,
            $comment_to_validate,
            isset($post['content']),
            $slideshow_present,
            $slideshow,
        );
    }
}
