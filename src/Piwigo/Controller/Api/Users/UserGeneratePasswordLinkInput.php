<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Users;

/**
 * `POST /api/v1/users/{id}/actions/generate-password-link` body DTO --
 * mirrors `Ws\Users\GeneratePasswordLinkParams`'s own `send_by_mail`
 * field.
 */
final readonly class UserGeneratePasswordLinkInput
{
    public function __construct(
        public bool $sendByMail,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $sendByMail = $raw['sendByMail'] ?? null;

        return new self(
            sendByMail: is_bool($sendByMail) ? $sendByMail : false,
        );
    }
}
