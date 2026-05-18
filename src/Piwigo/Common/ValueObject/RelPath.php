<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

/**
 * Storage-relative filesystem path (e.g. the `images.path` column,
 * `./galleries/2024/IMG_0001.jpg`). Constrained against directory
 * traversal — `..` segments and absolute paths are rejected — so the VO
 * can be safely concatenated under an absolute base.
 *
 * Length is capped at 255 chars to match every VARCHAR path column in
 * the schema (`images.path`, `images.file`, `categories.dir`, …).
 */
final readonly class RelPath implements StringVo
{
    private const MAX_LENGTH = 255;

    private function __construct(public string $value)
    {
    }

    /** @throws \InvalidArgumentException when $value contains `..` segments, null bytes, or starts with `/`. */
    #[\Override]
    public static function from(string $value): self
    {
        if ($value === '') {
            throw new \InvalidArgumentException('RelPath must not be empty');
        }
        if (strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(
                'RelPath exceeds ' . self::MAX_LENGTH . " chars: '{$value}'",
            );
        }
        if (str_contains($value, "\x00")) {
            throw new \InvalidArgumentException('RelPath must not contain null bytes');
        }
        if (str_starts_with($value, '/')) {
            throw new \InvalidArgumentException("RelPath must be relative, got absolute path: '{$value}'");
        }
        if (str_contains($value, '\\')) {
            throw new \InvalidArgumentException("RelPath must use forward slashes only: '{$value}'");
        }
        // Reject `..` as a path component (allow it inside a name like `foo..bar`).
        foreach (explode('/', $value) as $segment) {
            if ($segment === '..') {
                throw new \InvalidArgumentException("RelPath must not contain '..' segments: '{$value}'");
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
