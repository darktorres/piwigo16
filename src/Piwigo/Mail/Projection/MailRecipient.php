<?php

declare(strict_types=1);

namespace Piwigo\Mail\Projection;

use Piwigo\Users\UserStatus;

/**
 * Typed row shape shared by
 * {@see \Piwigo\Mail\MailRecipientRepository::findAdminsAndWebmasters()} and
 * {@see \Piwigo\Mail\MailRecipientRepository::findByGroupAndLanguage()} --
 * both are `users`/`user_infos` joins selecting the same core
 * `user_id`/`name`/`email` triple, differing only in whether
 * `status` (from `user_infos`) is also selected; `status` is simply `null`
 * for `findAdminsAndWebmasters()`'s own rows, which don't select it.
 *
 * `userId` stays a real `int`, not the raw string|numeric column value the
 * driver may hand back -- every real consumer
 * ({@see \Piwigo\Mail\MailService}) already narrows it to int on use.
 */
final readonly class MailRecipient
{
    public function __construct(
        public int $userId,
        public string $name,
        public string $email,
        public ?string $status,
    ) {}

    /**
     * @param array<string, mixed> $row a {@see \Piwigo\Mail\MailRecipientRepository::findAdminsAndWebmasters()}/{@see \Piwigo\Mail\MailRecipientRepository::findByGroupAndLanguage()} row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            userId: is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : 0,
            name: is_string($row['name'] ?? null) ? $row['name'] : '',
            email: is_string($row['email'] ?? null) ? $row['email'] : '',
            // See \Piwigo\Auth\Projection\AuthUser::fromRow()'s own comment
            // -- `ui.status` array-hydrates as a UserStatus instance, not a
            // raw string.
            status: ($row['status'] ?? null) instanceof UserStatus ? $row['status']->value : null,
        );
    }

    /**
     * @return array{user_id: int, name: string, email: string, status: ?string}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
        ];
    }
}
