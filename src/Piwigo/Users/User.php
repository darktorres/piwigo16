<?php

declare(strict_types=1);

namespace Piwigo\Users;

/**
 * Typed entity wrapping a Piwigo user record.
 *
 * Wave A: kept mutable so legacy code that writes $user['language'] = '...' and
 * then re-reads via CurrentUser::get() still works. All extra keys from plugins
 * or user_infos are preserved in rawAttributes.
 */
final class User
{
    /**
     * @param array<string,mixed> $internalStatus
     * @param array<string,mixed> $rawAttributes   Full original $user array, for legacy reads.
     */
    public function __construct(
        public readonly int $id,
        public string $username,
        public string $email,
        public string $language,
        public string $theme,
        public string $status,
        public bool $enabledHigh,
        public array $internalStatus = [],
        public array $rawAttributes = [],
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromUserArray(array $row): self
    {
        return new self(
            id: (int) ($row['id'] ?? 0),
            username: (string) ($row['username'] ?? ''),
            email: (string) ($row['email'] ?? ''),
            language: (string) ($row['language'] ?? 'en_US'),
            theme: (string) ($row['theme'] ?? 'elegant'),
            status: (string) ($row['status'] ?? 'guest'),
            enabledHigh: (bool) ($row['enabled_high'] ?? false),
            internalStatus: is_array($row['internal_status'] ?? null) ? $row['internal_status'] : [],
            rawAttributes: $row,
        );
    }
}
