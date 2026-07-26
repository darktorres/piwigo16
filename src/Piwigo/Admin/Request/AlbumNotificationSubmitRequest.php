<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_POST` shape for AlbumNotificationPageRenderer::render()'s
 * "notification" tab email form (page slug "album") -- P27/SEC-40 Request
 * DTO. `users`' own `InputValidator::validate()` call only runs when
 * `who === 'users'` and `users` is a non-empty array, matching the
 * original exactly; same for `group`'s validation, gated on
 * `who === 'group'` and `group` not being one of the original's
 * "empty" sentinel values. `group` stays `mixed` -- its own
 * `is_numeric(...) ? (int) ... : 0` narrowing stays at the call site,
 * since it's only ever read inside that same `who === 'group'` branch.
 */
final readonly class AlbumNotificationSubmitRequest
{
    /**
     * @param list<string> $users
     */
    private function __construct(
        public bool $isSubmitted,
        public string $mailContent,
        public ?string $who,
        public array $users,
        public mixed $group,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_POST);
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function fromArray(array $post): self
    {
        $mail_content = $post['mail_content'] ?? null;
        $mail_content = is_string($mail_content) ? $mail_content : '';

        $who = $post['who'] ?? null;
        $who = is_string($who) ? $who : null;

        $post_users = $post['users'] ?? null;
        $users = [];
        if ($who === 'users' and is_array($post_users) and count($post_users) > 0) {
            new InputValidator()->validate('users', $post, true, \Piwigo\Core\ValidationPattern::ID);

            foreach ($post_users as $post_user_id) {
                if (is_string($post_user_id)) {
                    $users[] = $post_user_id;
                }
            }
        }

        $group = $post['group'] ?? null;
        if ($who === 'group' and ! in_array($group, [null, false, 0, '0', '', []], true)) {
            new InputValidator()->validate('group', $post, false, \Piwigo\Core\ValidationPattern::ID);
        }

        return new self(
            isset($post['submitEmail']),
            $mail_content,
            $who,
            $users,
            $group,
        );
    }
}
