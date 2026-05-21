<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

/** (user_id, name, email) admin/webmaster recipient row from UserRepository::findAdminsForMail(). */
final readonly class UserMailRecipient
{
    public function __construct(
        public int $userId,
        public string $name,
        public string $email,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            userId: is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : 0,
            name:   is_string($row['name'] ?? null) ? $row['name'] : '',
            email:  is_string($row['email'] ?? null) ? $row['email'] : '',
        );
    }
}
