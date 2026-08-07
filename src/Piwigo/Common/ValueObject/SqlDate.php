<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Override;

/**
 * SQL DATE value in canonical `Y-m-d` form (no time component).
 *
 * Validates calendar arithmetic at construction (Feb 30, month 13, etc.
 * rejected) so downstream SQL composition, comparisons, and formatting
 * can treat the underlying string as well-formed. `Y-m-d` is the shared
 * canonical string form both MySQL's DATE and PostgreSQL's DATE columns
 * round-trip through this project's DBAL driver setup (confirmed live
 * against both), so this VO isn't engine-specific despite the shape
 * originating from MySQL's own output convention.
 *
 * Pairs with `SqlDateTime` for columns typed `DATE` rather than
 * `DATETIME`/`TIMESTAMP` (e.g. `images.date_metadata_update`).
 */
final readonly class SqlDate implements StringVo
{
    private function __construct(
        public string $value
    ) {}

    /**
     * @throws InvalidArgumentException when $value is not a valid `Y-m-d`
     */
    #[Override]
    public static function from(string $value): self
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("Invalid SQL date: '{$value}'");
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
        return new self($dt->format('Y-m-d'));
    }

    public function toDateTimeImmutable(): DateTimeImmutable
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $this->value);
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
