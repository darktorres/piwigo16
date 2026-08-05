<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

use InvalidArgumentException;
use Override;

/**
 * 32-character lowercase hexadecimal MD5 digest.
 *
 * Matches the `images.md5sum` CHAR(32) column. PHP's `md5()` and the
 * upload pipeline emit lowercase hex; uppercase input is rejected so two
 * Md5Sum instances compare meaningfully by `$a->value === $b->value`.
 */
final readonly class Md5Sum implements StringVo
{
    private const PATTERN = '/^[0-9a-f]{32}$/';

    private function __construct(
        public string $value
    ) {}

    /**
     * @throws InvalidArgumentException when $value is not 32 chars of lowercase hex.
     */
    #[Override]
    public static function from(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("Md5Sum must be 32 lowercase hex characters, got '{$value}'");
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
