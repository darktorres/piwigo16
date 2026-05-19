<?php

declare(strict_types=1);

namespace Piwigo\Users;

/**
 * Typed entity wrapping a Piwigo user record.
 *
 * Readonly: every "update" returns a fresh instance via a `with*` method.
 * `CurrentUser::update()` chains those into the singleton swap.
 *
 * `rawAttributes` carries plugin/user_infos extras that don't yet have
 * first-class fields. As the domain matures, callers should migrate to
 * typed accessors; in the meantime `withRawAttribute` keeps the bag honest.
 */
final readonly class User
{
    /**
     * @param array<mixed> $internalStatus
     * @param array<mixed> $rawAttributes   Full original $user array, for legacy reads.
     */
    public function __construct(
        public int $id,
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
        $id       = $row['id'] ?? 0;
        $username = $row['username'] ?? '';
        $email    = $row['email'] ?? '';
        $language = $row['language'] ?? 'en_US';
        $theme    = $row['theme'] ?? 'elegant';
        $status   = $row['status'] ?? 'guest';
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

    /** Returns a new User with `$language` updated (mirrored into rawAttributes). */
    public function withLanguage(string $language): self
    {
        $raw             = $this->rawAttributes;
        $raw['language'] = $language;
        return new self(
            id: $this->id,
            username: $this->username,
            email: $this->email,
            language: $language,
            theme: $this->theme,
            status: $this->status,
            enabledHigh: $this->enabledHigh,
            internalStatus: $this->internalStatus,
            rawAttributes: $raw,
        );
    }

    /** Returns a new User with `$username` updated (mirrored into rawAttributes). */
    public function withUsername(string $username): self
    {
        $raw             = $this->rawAttributes;
        $raw['username'] = $username;
        return new self(
            id: $this->id,
            username: $username,
            email: $this->email,
            language: $this->language,
            theme: $this->theme,
            status: $this->status,
            enabledHigh: $this->enabledHigh,
            internalStatus: $this->internalStatus,
            rawAttributes: $raw,
        );
    }

    /** Returns a new User with `$enabledHigh` updated (mirrored into rawAttributes). */
    public function withEnabledHigh(bool $enabledHigh): self
    {
        $raw                 = $this->rawAttributes;
        $raw['enabled_high'] = $enabledHigh;
        return new self(
            id: $this->id,
            username: $this->username,
            email: $this->email,
            language: $this->language,
            theme: $this->theme,
            status: $this->status,
            enabledHigh: $enabledHigh,
            internalStatus: $this->internalStatus,
            rawAttributes: $raw,
        );
    }

    /** Returns a new User with a single rawAttribute entry replaced. */
    public function withRawAttribute(string $key, mixed $value): self
    {
        $raw       = $this->rawAttributes;
        $raw[$key] = $value;
        return new self(
            id: $this->id,
            username: $this->username,
            email: $this->email,
            language: $this->language,
            theme: $this->theme,
            status: $this->status,
            enabledHigh: $this->enabledHigh,
            internalStatus: $this->internalStatus,
            rawAttributes: $raw,
        );
    }

    /** Returns a new User with a rawAttribute entry removed (no-op if absent). */
    public function withoutRawAttribute(string $key): self
    {
        $raw = $this->rawAttributes;
        unset($raw[$key]);
        return new self(
            id: $this->id,
            username: $this->username,
            email: $this->email,
            language: $this->language,
            theme: $this->theme,
            status: $this->status,
            enabledHigh: $this->enabledHigh,
            internalStatus: $this->internalStatus,
            rawAttributes: $raw,
        );
    }
}
