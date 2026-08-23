<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Users;

use Piwigo\Users\Projection\AccountFieldUpdates;

/**
 * Domain-shaped result of UserService::checkAndSaveUserInfos() -- either
 * a failure (a UserInfoUpdateFailureReason plus a human-readable
 * message) or a success (the ids actually updated, the user_infos
 * fields that were changed, and any account-table field changes).
 * Carries no transport-shaped value (no HTTP status of its own) -- a
 * caller's own transport layer maps failureReason onto whatever it needs.
 *
 * $infos stays a raw array, unlike $account (see AccountFieldUpdates) --
 * it's the same genuinely dynamic `user_infos` partial-update bag
 * UserRepository::updateInfosForUsers() takes (the caller composes it
 * from whichever fields actually changed, a real dynamic key set, not a
 * fixed row shape).
 */
final readonly class UserInfoUpdateResult
{
    /**
     * @param list<int> $userIds
     * @param array<string, mixed> $infos
     */
    private function __construct(
        public bool $isFailure,
        public ?UserInfoUpdateFailureReason $failureReason,
        public string $failureMessage,
        public array $userIds,
        public array $infos,
        public AccountFieldUpdates $account,
    ) {}

    public static function failure(UserInfoUpdateFailureReason $reason, string $message): self
    {
        return new self(true, $reason, $message, [], [], new AccountFieldUpdates());
    }

    /**
     * @param list<int> $userIds
     * @param array<string, mixed> $infos
     */
    public static function success(array $userIds, array $infos, AccountFieldUpdates $account): self
    {
        return new self(false, null, '', $userIds, $infos, $account);
    }
}
