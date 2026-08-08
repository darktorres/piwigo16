<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

/**
 * {@see \Piwigo\Users\UserService::getDefaultUserInfo()}'s own fixed
 * result shape -- {@see UserInfo::toArray()}'s shape minus 5 keys
 * (user_id/status/registration_date/last_visit/last_visit_from_history --
 * caller-specific, re-added by createUserInfos() itself).
 */
final readonly class DefaultUserInfo
{
    /**
     * @param array<string, mixed>|null $preferences
     */
    public function __construct(
        public int $nbImagePage,
        public string $language,
        public bool $expand,
        public bool $showNbComments,
        public bool $showNbHits,
        public int $recentPeriod,
        public string $theme,
        public bool $enabledHigh,
        public int $level,
        public ?string $activationKey,
        public ?string $activationKeyExpire,
        public string $lastmodified,
        public ?array $preferences,
    ) {}

    /**
     * $row comes from {@see \Piwigo\Users\UserService::getDefaultUserInfo()}'s
     * own `ProcessCache`-backed `'default_user'` entry -- real, narrowed
     * `UserInfo::toArray()` output on a cache miss, but `ProcessCache`
     * itself stores plain `mixed` values with no runtime shape
     * enforcement, so a stale/corrupted/hand-poisoned cache entry is a
     * real (if rare) possibility, same "don't trust a docblock-only
     * shape" reasoning as every other `fromRow()` factory in this
     * codebase.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $preferencesRaw = $row['preferences'] ?? null;

        return new self(
            nbImagePage: is_numeric($row['nb_image_page'] ?? null) ? (int) $row['nb_image_page'] : 0,
            language: is_string($row['language'] ?? null) ? $row['language'] : '',
            expand: (bool) ($row['expand'] ?? false),
            showNbComments: (bool) ($row['show_nb_comments'] ?? false),
            showNbHits: (bool) ($row['show_nb_hits'] ?? false),
            recentPeriod: is_numeric($row['recent_period'] ?? null) ? (int) $row['recent_period'] : 0,
            theme: is_string($row['theme'] ?? null) ? $row['theme'] : '',
            enabledHigh: (bool) ($row['enabled_high'] ?? false),
            level: is_numeric($row['level'] ?? null) ? (int) $row['level'] : 0,
            activationKey: is_string($row['activation_key'] ?? null) ? $row['activation_key'] : null,
            activationKeyExpire: is_string($row['activation_key_expire'] ?? null) ? $row['activation_key_expire'] : null,
            lastmodified: is_string($row['lastmodified'] ?? null) ? $row['lastmodified'] : '',
            preferences: is_array($preferencesRaw)
                ? array_filter($preferencesRaw, is_string(...), ARRAY_FILTER_USE_KEY)
                : null,
        );
    }

    /**
     * {@see \Piwigo\Users\UserService::createUserInfos()} array_merge()s
     * this with caller-supplied overrides, and {@see \Piwigo\Users\
     * UserService::getDefaultUserValue()} reads it by a runtime key name
     * -- unbox to array at both boundaries.
     *
     * @return array{nb_image_page: int, language: string, expand: bool, show_nb_comments: bool, show_nb_hits: bool, recent_period: int, theme: string, enabled_high: bool, level: int, activation_key: ?string, activation_key_expire: ?string, lastmodified: string, preferences: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'nb_image_page' => $this->nbImagePage,
            'language' => $this->language,
            'expand' => $this->expand,
            'show_nb_comments' => $this->showNbComments,
            'show_nb_hits' => $this->showNbHits,
            'recent_period' => $this->recentPeriod,
            'theme' => $this->theme,
            'enabled_high' => $this->enabledHigh,
            'level' => $this->level,
            'activation_key' => $this->activationKey,
            'activation_key_expire' => $this->activationKeyExpire,
            'lastmodified' => $this->lastmodified,
            'preferences' => $this->preferences,
        ];
    }
}
