<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

/**
 * {@see \Piwigo\Users\UserRepository::fetchUserInfosWithThemeName()}'s own
 * row shape -- the full {@see UserInfo} projection plus the LEFT JOINed
 * theme's display name -- {@see \Piwigo\Users\UserService::getUserData()}'s
 * own additional-row half of its `users`/`user_infos` merge.
 *
 * `toArray()` preserves the exact original snake_case shape, same
 * merge-boundary reasoning as {@see BasicUserRow}.
 */
final readonly class UserInfoWithThemeName
{
    public function __construct(
        public UserInfo $userInfo,
        public ?string $themeName,
    ) {}

    /**
     * @return array{user_id: int, nb_image_page: int, status: string, language: string,
     *   expand: bool, show_nb_comments: bool, show_nb_hits: bool, recent_period: int,
     *   theme: string, registration_date: ?string, enabled_high: bool, level: int,
     *   activation_key: ?string, activation_key_expire: ?string, last_visit: ?string,
     *   last_visit_from_history: bool, lastmodified: string, preferences: array<string, mixed>|null,
     *   theme_name: ?string}
     */
    public function toArray(): array
    {
        return [
            ...$this->userInfo->toArray(),
            'theme_name' => $this->themeName,
        ];
    }
}
