<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

/** (id, username, status) row from UserRepository::findAllWithStatus(). */
final readonly class UserStatusRow
{
    public function __construct(
        public int $id,
        public string $username,
        public string $status,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:       is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            username: is_string($row['username'] ?? null) ? $row['username'] : '',
            status:   is_string($row['status'] ?? null) ? $row['status'] : '',
        );
    }
}
