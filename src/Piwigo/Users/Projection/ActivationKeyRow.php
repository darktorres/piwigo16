<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

/** (user_id, status, activation_key) row from UserRepository::findByActiveActivationKey(). */
final readonly class ActivationKeyRow
{
    public function __construct(
        public int $userId,
        public string $status,
        public string $activationKey,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            userId:        is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : 0,
            status:        is_string($row['status'] ?? null) ? $row['status'] : '',
            activationKey: is_string($row['activation_key'] ?? null) ? $row['activation_key'] : '',
        );
    }
}
