<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

/**
 * MySQL DATE value in canonical `Y-m-d` form (no time component).
 *
 * Validates calendar arithmetic at construction (Feb 30, month 13, etc.
 * rejected) so downstream SQL composition, comparisons, and formatting
 * can treat the underlying string as well-formed.
 *
 * Pairs with `MysqlDateTime` for columns typed `DATE` rather than
 * `DATETIME` (e.g. `images.date_metadata_update`).
 */
final readonly class MysqlDate implements \Stringable
{
    private function __construct(
        public string $value
    ) {}

    /**
     * @throws \InvalidArgumentException when $value is not a valid `Y-m-d`
     */
    public static function from(string $value): self
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("Invalid MySQL date: '{$value}'");
        }
        return new self($value);
    }

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

    public static function fromDateTime(\DateTimeInterface $dt): self
    {
        return new self($dt->format('Y-m-d'));
    }

    public function toDateTimeImmutable(): \DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->value);
        assert($dt !== false);
        return $dt;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
