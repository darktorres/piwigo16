<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;

/**
 * {@see \Piwigo\Users\UserRepository::insertUserInfos()}'s own `$row`
 * shape -- {@see UserInfo}'s 17 non-`user_id` fields (`user_id` is the
 * separate `$userIds` param, applied once per inserted row). Every field
 * is nullable and defaults to `null`: an omitted field falls back to
 * `user_infos`'s own schema-declared column default inside
 * {@see \Piwigo\Users\UserRepository::buildUserInfoEntity()}, matching
 * the original raw-array `$row`'s "keys the caller omits fall back to the
 * schema default" contract exactly -- this VO only removes the untyped
 * array and its defensive `is_numeric`/`is_string` casts, not that
 * per-field omission behavior.
 */
final readonly class UserInfoInsertRow
{
    /**
     * @param array<string, mixed>|null $preferences
     */
    public function __construct(
        public ?int $nbImagePage = null,
        public ?string $status = null,
        public ?LangCode $language = null,
        public ?bool $expand = null,
        public ?bool $showNbComments = null,
        public ?bool $showNbHits = null,
        public ?int $recentPeriod = null,
        public ?ThemeId $theme = null,
        public ?string $registrationDate = null,
        public ?bool $enabledHigh = null,
        public ?int $level = null,
        public ?string $activationKey = null,
        public ?string $activationKeyExpire = null,
        public ?string $lastVisit = null,
        public ?bool $lastVisitFromHistory = null,
        public ?string $lastmodified = null,
        public ?array $preferences = null,
    ) {}
}
