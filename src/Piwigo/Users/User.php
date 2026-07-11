<?php

declare(strict_types=1);

namespace Piwigo\Users;

/**
 * Typed user entity. `rawAttributes` carries the full legacy `$user` array
 * for P17-23 reads during migration -- retirement gates per the plan doc:
 * P18 records a baseline read-count, P27's arch test enforces zero reads
 * outside `Users/`, P32 deletes the property entirely. Nothing populates
 * it yet (no caller has a full legacy `$user` array to hand in this
 * phase); the property exists now so `fromUserArray()`'s eventual P17-23
 * callers don't need a signature change.
 */
final readonly class User
{
    /**
     * @param array<string, mixed> $internalStatus
     * @param array<string, mixed> $rawAttributes
     */
    public function __construct(
        public int $id,
        public string $username,
        public string $email,
        public string $language,
        public string $theme,
        public UserStatus $status,
        public bool $enabledHigh,
        public array $internalStatus = [],
        public array $rawAttributes = [],
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromUserArray(array $row): self
    {
        $status = $row['status'] ?? null;

        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            username: is_string($row['username'] ?? null) ? $row['username'] : '',
            email: is_string($row['email'] ?? null) ? $row['email'] : '',
            language: is_string($row['language'] ?? null) ? $row['language'] : '',
            theme: is_string($row['theme'] ?? null) ? $row['theme'] : '',
            status: is_string($status) ? (UserStatus::tryFrom($status) ?? UserStatus::Guest) : UserStatus::Guest,
            enabledHigh: (bool) ($row['enabled_high'] ?? false),
            rawAttributes: $row,
        );
    }

    public function withLanguage(string $language): self
    {
        return new self(
            id: $this->id,
            username: $this->username,
            email: $this->email,
            language: $language,
            theme: $this->theme,
            status: $this->status,
            enabledHigh: $this->enabledHigh,
            internalStatus: $this->internalStatus,
            rawAttributes: $this->rawAttributes,
        );
    }

    public function withUsername(string $username): self
    {
        return new self(
            id: $this->id,
            username: $username,
            email: $this->email,
            language: $this->language,
            theme: $this->theme,
            status: $this->status,
            enabledHigh: $this->enabledHigh,
            internalStatus: $this->internalStatus,
            rawAttributes: $this->rawAttributes,
        );
    }

    public function withRawAttribute(string $key, mixed $value): self
    {
        $rawAttributes = $this->rawAttributes;
        $rawAttributes[$key] = $value;

        return new self(
            id: $this->id,
            username: $this->username,
            email: $this->email,
            language: $this->language,
            theme: $this->theme,
            status: $this->status,
            enabledHigh: $this->enabledHigh,
            internalStatus: $this->internalStatus,
            rawAttributes: $rawAttributes,
        );
    }
}
