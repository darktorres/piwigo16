<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Users;

/**
 * Domain-shaped result of UserService::checkAndSaveUserInfos() -- either
 * a failure (a UserInfoUpdateFailureReason plus a human-readable
 * message) or a success (the ids actually updated, the user_infos
 * fields that were changed, and any account-table field changes).
 * Carries no transport-shaped value (no WsError:: reference, no HTTP
 * status) -- a caller's own transport layer maps failureReason onto
 * whatever it needs.
 */
final readonly class UserInfoUpdateResult
{
    /**
     * @param list<int> $userIds
     * @param array<string, mixed> $infos
     * @param array<string, string> $account
     */
    private function __construct(
        public bool $isFailure,
        public ?UserInfoUpdateFailureReason $failureReason,
        public string $failureMessage,
        public array $userIds,
        public array $infos,
        public array $account,
    ) {}

    public static function failure(UserInfoUpdateFailureReason $reason, string $message): self
    {
        return new self(true, $reason, $message, [], [], []);
    }

    /**
     * @param list<int> $userIds
     * @param array<string, mixed> $infos
     * @param array<string, string> $account
     */
    public static function success(array $userIds, array $infos, array $account): self
    {
        return new self(false, null, '', $userIds, $infos, $account);
    }
}
