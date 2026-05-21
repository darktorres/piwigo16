<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

/** (user_id, status, name, email) group recipient row from UserRepository::findGroupRecipientsForLanguage(). */
final readonly class GroupMailRecipient
{
    public function __construct(
        public int $userId,
        public string $status,
        public string $name,
        public string $email,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            userId: is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : 0,
            status: is_string($row['status'] ?? null) ? $row['status'] : '',
            name:   is_string($row['name'] ?? null) ? $row['name'] : '',
            email:  is_string($row['email'] ?? null) ? $row['email'] : '',
        );
    }
}
