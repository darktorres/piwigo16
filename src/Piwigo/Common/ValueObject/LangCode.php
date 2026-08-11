<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

use InvalidArgumentException;
use Override;

/**
 * Piwigo language code in `ll_RR`/`lll_RR` form (e.g. `en_US`, `fr_FR`,
 * `pt_BR`, `kok_IN`).
 *
 * Matches every subdirectory under `language/` — two or three lowercase
 * letters (ISO 639-1 for almost every shipped pack, except `kok_IN`
 * (Konkani), which has no 639-1 code and ships under its 639-2/3 code),
 * an underscore, and two uppercase ISO 3166-1 letters. Used as a
 * filesystem path component for `language/<code>/*.po`, so the shape
 * constraint doubles as a directory-traversal guard.
 */
final readonly class LangCode implements StringVo
{
    private const string PATTERN = '/^[a-z]{2,3}_[A-Z]{2}$/';

    private function __construct(
        public string $value
    ) {}

    /**
     * @throws InvalidArgumentException when $value is not in `ll_RR` form
     */
    #[Override]
    public static function from(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("LangCode must match `ll_RR`, got '{$value}'");
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
