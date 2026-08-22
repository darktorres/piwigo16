<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * One entry of `user_activity.latte`'s `$ulist` filter dropdown, built by
 * {@see \Piwigo\Admin\UserActivityPageRenderer::render()} -- one row per
 * user with at least one logged activity row.
 */
final readonly class UserActivityUserRow
{
    public function __construct(
        public int $id,
        public string $username,
        public int $nbLines,
    ) {}

    /**
     * @return array{id: int, username: string, nb_lines: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'nb_lines' => $this->nbLines,
        ];
    }
}
