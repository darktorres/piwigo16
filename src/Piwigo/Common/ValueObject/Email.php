<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

/**
 * Email address. Validated with `filter_var(FILTER_VALIDATE_EMAIL)` and
 * capped at the `users.mail_address` column width (VARCHAR 255).
 *
 * The wrapped string is the canonical form returned by `filter_var` —
 * downstream code can concatenate it into SMTP envelopes, mailto: URLs,
 * or compare two Email instances by `$a->value === $b->value` without
 * re-validating.
 */
final readonly class Email implements StringVo
{
    private const MAX_LENGTH = 255;

    private function __construct(
        public string $value
    ) {}

    /**
     * @throws \InvalidArgumentException when $value is not a valid email or exceeds 255 chars
     */
    #[\Override]
    public static function from(string $value): self
    {
        if (strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(
                'Email exceeds ' . self::MAX_LENGTH . " chars: '{$value}'",
            );
        }
        $filtered = filter_var($value, FILTER_VALIDATE_EMAIL);
        if ($filtered === false) {
            throw new \InvalidArgumentException("Invalid email address: '{$value}'");
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
