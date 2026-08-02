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
     * @param array<int|string, mixed> $post
     */
    public static function fromArray(array $post): self
    {
        $mail_content = $post['mail_content'] ?? null;
        $mail_content = is_string($mail_content) ? $mail_content : '';

        $who = $post['who'] ?? null;
        $who = is_string($who) ? $who : null;

        $post_users = $post['users'] ?? null;
        $users = [];
        // The `count(...) > 0` guard is redundant with InputValidator::
        // validate()'s own internal emptyValue() check ($paramValue === []
        // is one of its cases) -- calling validate() on an empty
        // $post_users array is already a silent no-op (mandatory defaults
        // to false), so `> 0` vs `>= 0`/`> -1` produce identical final
        // $users output. Confirmed while investigating a mutation-testing
        // gap, same redundancy as the `group` sentinel array below.
        if ($who === 'users' and is_array($post_users) and count($post_users) > 0) {
            InputValidator::createStatic()
                ->validate('users', $post, true, \Piwigo\Core\ValidationPattern::ID);

            foreach ($post_users as $post_user_id) {
                if (is_string($post_user_id)) {
                    $users[] = $post_user_id;
                }
            }
        }

        $group = $post['group'] ?? null;
        // This sentinel list is a strict subset of InputValidator::
        // validate()'s own internal emptyValue() check (null/''/0/0.0/'0'/
        // false/[] -- 0.0 is the one value missing here), so every one of
        // these 6 literals is *also* independently caught by validate()
        // itself, which silently no-ops on an "empty" $paramValue rather
        // than validating it. That makes this array's exact contents
        // unobservable: manually verified (temporarily emptying the array
        // entirely) that all 6 sentinels -- and 0.0, the one this array
        // doesn't list -- still produce identical (non-throwing) behavior
        // either way. `who === 'group'` is the only real gate here.
        if ($who === 'group' and ! in_array($group, [null, false, 0, '0', '', []], true)) {
            InputValidator::createStatic()
                ->validate('group', $post, false, \Piwigo\Core\ValidationPattern::ID);
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
