<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Users;

/**
 * `PATCH /api/v1/users/{id}` body DTO. Fields are all optional (leave one
 * blank to keep the current value), including the 6 per-user
 * UI-preference fields (nbImagePage/recentPeriod/expand/showNbComments/
 * showNbHits/enabledHigh) -- `user_list.js`'s edit-user popup reads and
 * writes all 6 through its preferences tab, so dropping them broke that
 * tab's controls entirely when this endpoint got its first real JS
 * caller.
 */
final readonly class UserUpdateInput
{
    /**
     * @param list<int>|null $groupIds
     */
    public function __construct(
        public ?string $username,
        public ?string $password,
        public ?string $email,
        public ?string $status,
        public ?int $level,
        public ?string $language,
        public ?string $theme,
        public ?array $groupIds,
        public ?int $nbImagePage,
        public ?int $recentPeriod,
        public ?bool $expand,
        public ?bool $showNbComments,
        public ?bool $showNbHits,
        public ?bool $enabledHigh,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $username = $raw['username'] ?? null;
        $password = $raw['password'] ?? null;
        $email = $raw['email'] ?? null;
        $status = $raw['status'] ?? null;
        $level = $raw['level'] ?? null;
        $language = $raw['language'] ?? null;
        $theme = $raw['theme'] ?? null;
        $groupIds = $raw['groupIds'] ?? null;
        $nbImagePage = $raw['nbImagePage'] ?? null;
        $recentPeriod = $raw['recentPeriod'] ?? null;
        $expand = $raw['expand'] ?? null;
        $showNbComments = $raw['showNbComments'] ?? null;
        $showNbHits = $raw['showNbHits'] ?? null;
        $enabledHigh = $raw['enabledHigh'] ?? null;

        return new self(
            username: is_string($username) ? $username : null,
            password: is_string($password) ? $password : null,
            email: is_string($email) ? $email : null,
            status: is_string($status) ? $status : null,
            level: is_int($level) ? $level : null,
            language: is_string($language) ? $language : null,
            theme: is_string($theme) ? $theme : null,
            groupIds: is_array($groupIds) ? self::intList($groupIds) : null,
            nbImagePage: is_int($nbImagePage) ? $nbImagePage : null,
            recentPeriod: is_int($recentPeriod) ? $recentPeriod : null,
            expand: is_bool($expand) ? $expand : null,
            showNbComments: is_bool($showNbComments) ? $showNbComments : null,
            showNbHits: is_bool($showNbHits) ? $showNbHits : null,
            enabledHigh: is_bool($enabledHigh) ? $enabledHigh : null,
        );
    }

    /**
     * @param array<mixed> $raw
     * @return list<int>
     */
    private static function intList(array $raw): array
    {
        $ids = [];
        foreach ($raw as $v) {
            if (is_int($v)) {
                $ids[] = $v;
            } elseif (is_numeric($v)) {
                $ids[] = (int) $v;
            }
        }

        return $ids;
    }
}
