<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Override;

/**
 * MySQL DATETIME value in canonical `Y-m-d H:i:s` form.
 *
 * Validates calendar arithmetic at construction (Feb 30, hour 25, etc. are
 * rejected) so downstream SQL composition, comparisons, and formatting can
 * treat the underlying string as well-formed.
 *
 * The wrapped string is the canonical form — concatenating into SQL or
 * comparing two MysqlDateTime instances by `$a->value === $b->value` is
 * always meaningful. Conversion to a `\DateTimeImmutable` is available via
 * `toDateTimeImmutable()` for arithmetic; round-trips are lossless.
 */
final readonly class MysqlDateTime implements StringVo
{
    private function __construct(
        public string $value
    ) {}

    /**
     * @throws InvalidArgumentException when $value is not a valid `Y-m-d H:i:s`
     */
    #[Override]
    public static function from(string $value): self
    {
        // The `!` resets all unspecified fields so partial inputs (e.g. just a date) fail.
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        // Round-trip catches lenient parsing — e.g. '2026-02-30' silently rolls to '2026-03-02'.
        if ($dt === false || $dt->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException("Invalid MySQL datetime: '{$value}'");
        }
        return new self($value);
    }

    #[Override]
    public static function tryFrom(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }
        try {
            return self::from($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function fromDateTime(DateTimeInterface $dt): self
    {
        return new self($dt->format('Y-m-d H:i:s'));
    }

    public static function now(): self
    {
        return new self((new DateTimeImmutable())->format('Y-m-d H:i:s'));
    }

    public function toDateTimeImmutable(): DateTimeImmutable
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $this->value);
        // Construction guarantees this parses — assert documents the invariant.
        assert($dt !== false);
        return $dt;
    }

    #[Override]
    public function equals(StringVo $other): bool
    {
        return $other instanceof self && $other->value === $this->value;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
