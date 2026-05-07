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
     * @param array<mixed> $internalStatus
     * @param array<mixed> $rawAttributes   Full original $user array, for legacy reads.
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

    /** @param array<mixed> $row */
    public static function fromUserArray(array $row): self
    {
        $id = $row['id'] ?? 0;
        $username = $row['username'] ?? '';
        $email = $row['email'] ?? '';
        $language = $row['language'] ?? 'en_US';
        $theme = $row['theme'] ?? 'elegant';
        $status = $row['status'] ?? 'guest';
        return new self(
            id: is_scalar($id) ? (int) $id : 0,
            username: is_scalar($username) ? (string) $username : '',
            email: is_scalar($email) ? (string) $email : '',
            language: is_scalar($language) ? (string) $language : 'en_US',
            theme: is_scalar($theme) ? (string) $theme : 'elegant',
            status: is_scalar($status) ? (string) $status : 'guest',
            enabledHigh: (bool) ($row['enabled_high'] ?? false),
            internalStatus: is_array($row['internal_status'] ?? null) ? $row['internal_status'] : [],
            rawAttributes: $row,
        );
    }
}
