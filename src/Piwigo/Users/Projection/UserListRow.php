<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

use Piwigo\Db\SqlDialect;

/**
 * {@see \Piwigo\Controller\Api\Users\UserRowFetcher::DISPLAY_COLUMNS}'s
 * own fixed row shape -- the sole real caller of {@see
 * \Piwigo\Users\UserRepository::findList()} always supplies that exact
 * column set (a JSON API returns complete, correctly-typed rows rather
 * than replicating `pwg.users.getList`'s client-controlled `display`
 * mini-language, see that class's own docblock), so `findList()`/
 * `UserService::getList()` themselves stay generic
 * `array<string, mixed>`-returning (a real caller-selected-column API,
 * not this one fixed shape) while `UserRowFetcher` converts to this row
 * right at its own boundary.
 */
final readonly class UserListRow
{
    public function __construct(
        public int $id,
        public string $username,
        public ?string $email,
        public ?string $status,
        public ?int $level,
        public ?string $language,
        public ?string $theme,
        public ?string $registrationDate,
        public ?string $lastVisit,
        public bool $lastVisitFromHistory,
        public ?int $nbImagePage,
        public ?int $recentPeriod,
        public bool $expand,
        public bool $showNbComments,
        public bool $showNbHits,
        public bool $enabledHigh,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            username: is_string($row['username'] ?? null) ? $row['username'] : '',
            email: is_string($row['email'] ?? null) ? $row['email'] : null,
            status: is_string($row['status'] ?? null) ? $row['status'] : null,
            level: is_numeric($row['level'] ?? null) ? (int) $row['level'] : null,
            language: is_string($row['language'] ?? null) ? $row['language'] : null,
            theme: is_string($row['theme'] ?? null) ? $row['theme'] : null,
            registrationDate: is_string($row['registration_date'] ?? null) ? $row['registration_date'] : null,
            lastVisit: is_string($row['last_visit'] ?? null) ? $row['last_visit'] : null,
            lastVisitFromHistory: SqlDialect::getBoolean($row['last_visit_from_history'] ?? null),
            nbImagePage: is_numeric($row['nb_image_page'] ?? null) ? (int) $row['nb_image_page'] : null,
            recentPeriod: is_numeric($row['recent_period'] ?? null) ? (int) $row['recent_period'] : null,
            expand: SqlDialect::getBoolean($row['expand'] ?? null),
            showNbComments: SqlDialect::getBoolean($row['show_nb_comments'] ?? null),
            showNbHits: SqlDialect::getBoolean($row['show_nb_hits'] ?? null),
            enabledHigh: SqlDialect::getBoolean($row['enabled_high'] ?? null),
        );
    }
}
