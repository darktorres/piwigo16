<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

/**
 * Absolute POSIX filesystem path (must start with `/`).
 *
 * Used for resolved disk locations — `Paths::$root`, derivative storage,
 * upload destinations. Pairs with `RelPath`: composing an `AbsPath` with
 * a `RelPath` yields a well-formed absolute path with no traversal.
 *
 * Existence on disk is NOT checked here — the VO captures the *shape* of
 * a path, not its current existence (which can race).
 */
final readonly class AbsPath implements StringVo
{
    private function __construct(public string $value)
    {
    }

    /** @throws \InvalidArgumentException when $value is not absolute or contains null bytes or `..` segments. */
    #[\Override]
    public static function from(string $value): self
    {
        if ($value === '') {
            throw new \InvalidArgumentException('AbsPath must not be empty');
        }
        if (!str_starts_with($value, '/')) {
            throw new \InvalidArgumentException("AbsPath must start with '/', got: '{$value}'");
        }
        if (str_contains($value, "\x00")) {
            throw new \InvalidArgumentException('AbsPath must not contain null bytes');
        }
        if (str_contains($value, '\\')) {
            throw new \InvalidArgumentException("AbsPath must use forward slashes only: '{$value}'");
        }
        foreach (explode('/', $value) as $segment) {
            if ($segment === '..') {
                throw new \InvalidArgumentException("AbsPath must not contain '..' segments: '{$value}'");
            }
        }
        return new self($value);
    }

    #[\Override]
    public static function tryFrom(mixed $value): ?self
    {
        if (!is_string($value)) {
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
