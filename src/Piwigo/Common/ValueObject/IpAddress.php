<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

/**
 * Client IP address (IPv4 or IPv6), validated with
 * `filter_var(FILTER_VALIDATE_IP)`.
 *
 * `fromRemoteAddr()` is the single canonical reader for
 * `$_SERVER['REMOTE_ADDR']` -- every real call site used to re-derive its
 * own `isset()`/`is_string()`/fallback narrowing independently (12 sites
 * across 11 files, found during a primitive-obsession sweep), with 4 of
 * them skipping the `is_string()` guard entirely. Callers still pick their
 * own "no IP" fallback explicitly at the point of use (`->value ?? ''`
 * for a display/storage default -- `??`'s own semantics already suppress
 * the "read property on null" warning, so a plain `->` is correct and
 * PHPStan's `nullsafe.neverNull` rule rejects the `?->value ?? ''` form
 * as redundant; `?->value` alone, with no `??`, to pass null through) --
 * this class only centralises the parsing/validation, not each call
 * site's own default, since those differ by real DB column nullability
 * (e.g. `audit_log.ip_address`/`activity.ip_address` are nullable,
 * `history.IP`/`rate.anonymous_id` are `NOT NULL DEFAULT ''`).
 */
final readonly class IpAddress implements StringVo
{
    private function __construct(
        public string $value
    ) {}

    /**
     * @throws \InvalidArgumentException when $value is not a valid IPv4 or IPv6 address
     */
    #[\Override]
    public static function from(string $value): self
    {
        $filtered = filter_var($value, FILTER_VALIDATE_IP);
        if ($filtered === false) {
            throw new \InvalidArgumentException("Invalid IP address: '{$value}'");
        }
        return new self($filtered);
    }

    #[\Override]
    public static function tryFrom(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }
        try {
            return self::from($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Reads and validates `$_SERVER['REMOTE_ADDR']`. Null when unset, not
     * a string, or not a valid IP -- callers that need a non-null default
     * supply their own via `->value ?? '...'`.
     */
    public static function fromRemoteAddr(): ?self
    {
        $value = $_SERVER['REMOTE_ADDR'] ?? null;
        return is_string($value) ? self::tryFrom($value) : null;
    }

    #[\Override]
    public function equals(StringVo $other): bool
    {
        return $other instanceof self && $other->value === $this->value;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
