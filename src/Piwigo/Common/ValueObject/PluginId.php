<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

use InvalidArgumentException;
use Override;

/**
 * Plugin identifier — directory-name slug, also the manifest `id` field.
 *
 * Shape matches `docs/schemas/plugin.schema.json` (`^[a-zA-Z0-9_-]+$`, 1-64
 * chars). Used as a filesystem path component for `plugins/<id>/…`, so the
 * shape constraint doubles as a directory-traversal guard.
 */
final readonly class PluginId implements StringVo
{
    private const string PATTERN = '/^[a-zA-Z0-9_-]{1,64}$/';

    private function __construct(
        public string $value
    ) {}

    /**
     * @throws InvalidArgumentException when $value does not match `[a-zA-Z0-9_-]{1,64}`
     */
    #[Override]
    public static function from(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("PluginId must be 1-64 chars of [a-zA-Z0-9_-], got '{$value}'");
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
