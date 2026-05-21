<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

/** (user_id, status, language, email, username) row from UserRepository::findMailRecipientInfoByIds(). */
final readonly class MailRecipientFull
{
    public function __construct(
        public int $userId,
        public string $status,
        public string $language,
        public string $email,
        public string $username,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            userId:   is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : 0,
            status:   is_string($row['status'] ?? null) ? $row['status'] : '',
            language: is_string($row['language'] ?? null) ? $row['language'] : '',
            email:    is_string($row['email'] ?? null) ? $row['email'] : '',
            username: is_string($row['username'] ?? null) ? $row['username'] : '',
        );
    }
}
