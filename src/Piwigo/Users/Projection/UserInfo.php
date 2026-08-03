<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\ArrayHelper;
use Piwigo\Users\UserInfoEntity;

/**
 * Typed row shape for `piwigo_user_infos` (P17-23 Stage 1b, User domain --
 * `docs/PLAN.md`'s own "7 Entity types, 73 projection shapes"
 * reference). `fromRow()` centralises the `is_string($row['x']) ? ... :
 * default` narrowing every {@see \Piwigo\Users\UserRepository} caller used
 * to duplicate for itself, same shape as
 * {@see \Piwigo\Category\Projection\Category}.
 *
 * `findDefaultUserInfoRow()`, the only real caller, actually builds this
 * via `fromEntity()` instead (it already holds a loaded `UserInfoEntity`
 * from Doctrine) -- `fromRow()` stays available for a future raw-row
 * caller but has none today. `UserService::getUserData()`'s own 3-way raw
 * JOIN (`users`/`user_infos`/`user_cache`) stays a raw array; that query
 * never selects a clean single-table row this projection could
 * represent.
 *
 * `expand`/`show_nb_comments`/`show_nb_hits`/`enabled_high`/
 * `last_visit_from_history` are real `bool` here, not the raw tinyint the
 * column now stores -- User domain Stage 1a already made them a genuine
 * boolean column; a repository-layer projection is the correct place to
 * finish that conversion to a real PHP `bool` once.
 *
 * `registration_date`/`last_visit`/`activation_key`/`activation_key_expire`
 * stay `?string`, not `\DateTimeImmutable` -- every real consumer today
 * expects the raw DB DATETIME string form, same reasoning as
 * {@see \Piwigo\Image\Projection\Image}. `preferences` is `?array`, decoded
 * via `ArrayHelper::safeJsonDecode()` -- the column is JSON (gap-closure
 * Stage 1a-bis item 3), matching {@see \Piwigo\Users\User::fromUserArray()}'s
 * own already-decoded-array expectation for the same data.
 */
final readonly class UserInfo
{
    /**
     * @param array<string, mixed>|null $preferences
     */
    public function __construct(
        public UserId $userId,
        public int $nbImagePage,
        public string $status,
        public string $language,
        public bool $expand,
        public bool $showNbComments,
        public bool $showNbHits,
        public int $recentPeriod,
        public string $theme,
        public ?string $registrationDate,
        public bool $enabledHigh,
        public int $level,
        public ?string $activationKey,
        public ?string $activationKeyExpire,
        public ?string $lastVisit,
        public bool $lastVisitFromHistory,
        public string $lastmodified,
        public ?array $preferences,
    ) {}

    /**
     * @param array<string, mixed> $row a `SELECT *` (or equivalent) row from `piwigo_user_infos`
     */
    public static function fromRow(array $row): self
    {
        $preferencesRaw = $row['preferences'] ?? null;

        $userId = UserId::tryFrom($row['user_id'] ?? null);
        if ($userId === null) {
            throw new \InvalidArgumentException('UserInfo::fromRow(): missing or invalid user_id');
        }

        return new self(
            userId: $userId,
            nbImagePage: is_numeric($row['nb_image_page'] ?? null) ? (int) $row['nb_image_page'] : 0,
            status: is_string($row['status'] ?? null) ? $row['status'] : 'guest',
            language: is_string($row['language'] ?? null) ? $row['language'] : '',
            expand: (bool) ($row['expand'] ?? false),
            showNbComments: (bool) ($row['show_nb_comments'] ?? false),
            showNbHits: (bool) ($row['show_nb_hits'] ?? false),
            recentPeriod: is_numeric($row['recent_period'] ?? null) ? (int) $row['recent_period'] : 0,
            theme: is_string($row['theme'] ?? null) ? $row['theme'] : '',
            registrationDate: is_string($row['registration_date'] ?? null) ? $row['registration_date'] : null,
            enabledHigh: (bool) ($row['enabled_high'] ?? false),
            level: is_numeric($row['level'] ?? null) ? (int) $row['level'] : 0,
            activationKey: is_string($row['activation_key'] ?? null) ? $row['activation_key'] : null,
            activationKeyExpire: is_string($row['activation_key_expire'] ?? null) ? $row['activation_key_expire'] : null,
            lastVisit: is_string($row['last_visit'] ?? null) ? $row['last_visit'] : null,
            lastVisitFromHistory: (bool) ($row['last_visit_from_history'] ?? false),
            lastmodified: is_string($row['lastmodified'] ?? null) ? $row['lastmodified'] : '',
            preferences: is_string($preferencesRaw)
                ? array_filter(ArrayHelper::safeJsonDecode($preferencesRaw), is_string(...), ARRAY_FILTER_USE_KEY)
                : null,
        );
    }

    public static function fromEntity(UserInfoEntity $entity): self
    {
        return new self(
            userId: $entity->userId,
            nbImagePage: $entity->nbImagePage,
            status: $entity->status->value,
            language: $entity->language,
            expand: $entity->expand,
            showNbComments: $entity->showNbComments,
            showNbHits: $entity->showNbHits,
            recentPeriod: $entity->recentPeriod,
            theme: $entity->theme,
            registrationDate: $entity->registrationDate,
            enabledHigh: $entity->enabledHigh,
            level: $entity->level,
            activationKey: $entity->activationKey,
            activationKeyExpire: $entity->activationKeyExpire,
            lastVisit: $entity->lastVisit,
            lastVisitFromHistory: $entity->lastVisitFromHistory,
            lastmodified: $entity->lastmodified,
            preferences: $entity->preferences,
        );
    }

    /**
     * @return array{user_id: int, nb_image_page: int, status: string, language: string,
     *   expand: bool, show_nb_comments: bool, show_nb_hits: bool, recent_period: int,
     *   theme: string, registration_date: ?string, enabled_high: bool, level: int,
     *   activation_key: ?string, activation_key_expire: ?string, last_visit: ?string,
     *   last_visit_from_history: bool, lastmodified: string, preferences: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId->value,
            'nb_image_page' => $this->nbImagePage,
            'status' => $this->status,
            'language' => $this->language,
            'expand' => $this->expand,
            'show_nb_comments' => $this->showNbComments,
            'show_nb_hits' => $this->showNbHits,
            'recent_period' => $this->recentPeriod,
            'theme' => $this->theme,
            'registration_date' => $this->registrationDate,
            'enabled_high' => $this->enabledHigh,
            'level' => $this->level,
            'activation_key' => $this->activationKey,
            'activation_key_expire' => $this->activationKeyExpire,
            'last_visit' => $this->lastVisit,
            'last_visit_from_history' => $this->lastVisitFromHistory,
            'lastmodified' => $this->lastmodified,
            'preferences' => $this->preferences,
        ];
    }
}
