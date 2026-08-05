<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

use InvalidArgumentException;
use Override;

/**
 * Category permalink slug — URL-safe identifier stored in
 * `categories.permalink` (VARCHAR 64, utf8mb4_bin, UNIQUE).
 *
 * Constraints mirror `PermalinkService::setCatPermalink`:
 *   - Characters: `[a-zA-Z0-9_/-]` only.
 *   - No leading or trailing `/`.
 *   - No consecutive `/` (so segments are non-empty).
 *   - Not purely numeric (`123` or `123-foo` are rejected to keep
 *     numeric paths unambiguously interpretable as category IDs).
 *   - 1-64 chars.
 *
 * Hierarchical paths (e.g. `events/2024-summer`) are supported.
 */
final readonly class Permalink implements StringVo
{
    private const MAX_LENGTH = 64;

    private const CHARSET_PATTERN = '#^[a-zA-Z0-9_/-]+$#';

    private const NUMERIC_PATTERN = '#^(\d)+(-.*)?$#';

    private function __construct(
        public string $value
    ) {}

    /**
     * @throws InvalidArgumentException when $value violates any of the documented constraints.
     */
    #[Override]
    public static function from(string $value): self
    {
        if ($value === '') {
            throw new InvalidArgumentException('Permalink must not be empty');
        }
        if (strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                'Permalink exceeds ' . self::MAX_LENGTH . " chars: '{$value}'",
            );
        }
        if (preg_match(self::CHARSET_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                "Permalink must contain only [a-zA-Z0-9_/-]: '{$value}'",
            );
        }
        if (str_starts_with($value, '/') || str_ends_with($value, '/')) {
            throw new InvalidArgumentException(
                "Permalink must not start or end with '/': '{$value}'",
            );
        }
        if (str_contains($value, '//')) {
            throw new InvalidArgumentException(
                "Permalink must not contain consecutive '/': '{$value}'",
            );
        }
        if (preg_match(self::NUMERIC_PATTERN, $value) === 1) {
            throw new InvalidArgumentException(
                "Permalink must not be numeric or start with `<digits>-`: '{$value}'",
            );
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
