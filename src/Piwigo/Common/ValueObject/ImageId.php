<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

/**
 * Positive integer identifier of a row in the `images` table.
 *
 * Constructed only through validating factories — once you hold an ImageId
 * you are guaranteed it is a positive integer, so downstream code (queries,
 * URL generation, comparisons) never re-validates.
 *
 * Use `from()` when the value is known to be an int (DB hydration, internal
 * arithmetic); use `tryFrom()` at parsing boundaries (request params, JSON,
 * untyped arrays) where the input might be malformed.
 */
final readonly class ImageId implements NumericId
{
    private function __construct(
        public int $value
    ) {}

    /**
     * @throws \InvalidArgumentException when $value is not positive
     */
    #[\Override]
    public static function from(int $value): self
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException("ImageId must be a positive integer, got {$value}");
        }
        return new self($value);
    }

    /**
     * Returns null when $value cannot be coerced to a positive integer (no overflow, no decimals, no 'e' notation).
     */
    #[\Override]
    public static function tryFrom(mixed $value): ?self
    {
        if (is_int($value)) {
            return $value > 0 ? new self($value) : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;
            return $int > 0 ? new self($int) : null;
        }
        return null;
    }

    #[\Override]
    public function equals(NumericId $other): bool
    {
        return $other instanceof self && $other->value === $this->value;
    }

    #[\Override]
    public function __toString(): string
    {
        return (string) $this->value;
    }
}
