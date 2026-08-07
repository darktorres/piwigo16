<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Override;

/**
 * SQL TIME value in canonical `H:i:s` form (no date component).
 *
 * Validates calendar arithmetic at construction (hour 25, minute 60, etc.
 * rejected) so downstream SQL composition, comparisons, and formatting
 * can treat the underlying string as well-formed. `H:i:s` is the shared
 * canonical string form both MySQL's TIME and PostgreSQL's TIME columns
 * round-trip through this project's DBAL driver setup, same reasoning as
 * `SqlDate`/`SqlDateTime`.
 *
 * `piwigo_history.time` is currently the only real `TIME`-typed column in
 * this schema.
 */
final readonly class SqlTime implements StringVo
{
    private function __construct(
        public string $value
    ) {}

    /**
     * @throws InvalidArgumentException when $value is not a valid `H:i:s`
     */
    #[Override]
    public static function from(string $value): self
    {
        $dt = DateTimeImmutable::createFromFormat('!H:i:s', $value);
        if ($dt === false || $dt->format('H:i:s') !== $value) {
            throw new InvalidArgumentException("Invalid SQL time: '{$value}'");
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
        return new self($dt->format('H:i:s'));
    }

    public function toDateTimeImmutable(): DateTimeImmutable
    {
        $dt = DateTimeImmutable::createFromFormat('!H:i:s', $this->value);
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
