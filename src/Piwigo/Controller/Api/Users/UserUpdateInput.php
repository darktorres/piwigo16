<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Users;

/**
 * `PATCH /api/v1/users/{id}` body DTO -- mirrors `Ws\Users\SetInfoParams`'s
 * own optional fields ("leave a field blank to keep the current value").
 * The 6 fields `Ws\Users\UsersMethodRegistrar`'s own comment already
 * flags as "removed in a future version" (nb_image_page, recent_period,
 * expand, show_nb_comments, show_nb_hits, enabled_high) are dropped here
 * entirely rather than carried into a fresh surface.
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

        return new self(
            username: is_string($username) ? $username : null,
            password: is_string($password) ? $password : null,
            email: is_string($email) ? $email : null,
            status: is_string($status) ? $status : null,
            level: is_int($level) ? $level : null,
            language: is_string($language) ? $language : null,
            theme: is_string($theme) ? $theme : null,
            groupIds: is_array($groupIds) ? self::intList($groupIds) : null,
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
