<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

/** (id, username, email, password, status) row from UserRepository::findByUsernameOrEmail() — auth path only. */
final readonly class UserCredentialRow
{
    public function __construct(
        public int $id,
        public string $username,
        public string $email,
        public string $password,
        public string $status,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:       is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            username: is_string($row['username'] ?? null) ? $row['username'] : '',
            email:    is_string($row['email'] ?? null) ? $row['email'] : '',
            password: is_string($row['password'] ?? null) ? $row['password'] : '',
            status:   is_string($row['status'] ?? null) ? $row['status'] : '',
        );
    }

    /** Convert to legacy array format for callers that need array access. */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'username' => $this->username,
            'email'    => $this->email,
            'password' => $this->password,
            'status'   => $this->status,
        ];
    }
}
