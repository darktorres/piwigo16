<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

/**
 * Positive integer identifier of a row in the `comments` table.
 *
 * Strict `from(int)` for known-int call sites (DB hydration, internal use);
 * lenient `tryFrom(mixed)` at parsing boundaries.
 */
final readonly class CommentId implements NumericId
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
            throw new \InvalidArgumentException("CommentId must be a positive integer, got {$value}");
        }
        return new self($value);
    }

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
