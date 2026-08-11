<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

use InvalidArgumentException;
use Piwigo\Common\ValueObject\UserId;

/**
 * Typed row shape for `user_infos`'s `(user_id, status,
 * activation_key)` triple -- the reset-password activation-key scan
 * {@see \Piwigo\Controller\PasswordController::checkPasswordResetKey()}
 * runs over every row with a non-expired `activation_key`. `fromRow()`
 * centralises the narrowing that method used to duplicate for itself per
 * loop iteration.
 */
final readonly class ActivationKeyRow
{
    public function __construct(
        public UserId $userId,
        public string $status,
        public string $activationKey,
    ) {}

    /**
     * @param array<string, mixed> $row a `SELECT user_id, status,
     *   activation_key` row from `user_infos`
     */
    public static function fromRow(array $row): self
    {
        $userId = UserId::tryFrom($row['user_id'] ?? null);
        if (! $userId instanceof UserId) {
            throw new InvalidArgumentException('ActivationKeyRow::fromRow(): missing or invalid user_id');
        }

        return new self(
            userId: $userId,
            status: is_string($row['status'] ?? null) ? $row['status'] : '',
            activationKey: is_string($row['activation_key'] ?? null) ? $row['activation_key'] : '',
        );
    }
}
