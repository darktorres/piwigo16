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
 * Domain-shaped input for UserService::checkAndSaveUserInfos() -- built
 * by a caller's own typed request DTO (e.g. `Controller\Api\Users\
 * UserUpdateInput`/`Controller\Api\Session\MyInfoUpdateInput`), not
 * parsed from a raw transport array directly.
 *
 * Every field but $userIds is null when not supplied by the caller, same
 * "absent means null" convention every field already used when it lived
 * on the raw WS $params array -- checkAndSaveUserInfos()'s own
 * self::emptyValue() checks treat a supplied-but-empty value
 * (''/0/'0'/false/[]) identically to an absent one already, so this
 * carries the value through unchanged rather than pre-collapsing it.
 */
final readonly class UserInfoUpdateInput
{
    /**
     * @param list<int> $userIds
     * @param list<int>|null $groupIds
     */
    public function __construct(
        public array $userIds,
        public ?string $username = null,
        public ?string $password = null,
        public ?string $email = null,
        public ?string $status = null,
        public ?int $level = null,
        public ?string $language = null,
        public ?string $theme = null,
        public ?array $groupIds = null,
        public ?int $nbImagePage = null,
        public ?int $recentPeriod = null,
        public ?bool $expand = null,
        public ?bool $showNbComments = null,
        public ?bool $showNbHits = null,
        public ?bool $enabledHigh = null,
    ) {}
}
