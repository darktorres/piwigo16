<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

/**
 * Absolute URL with a scheme and host, validated by
 * `filter_var(FILTER_VALIDATE_URL)`.
 *
 * Used for derivative-image URLs, external links emitted in feeds and
 * mail, telemetry endpoints, and OpenAPI server URLs. Relative URLs
 * belong in `RelPath` (filesystem) or a separate `RelativeUrl` VO if
 * routing-level relative paths become a thing.
 */
final readonly class Url implements StringVo
{
    private function __construct(public string $value)
    {
    }

    /** @throws \InvalidArgumentException when $value fails FILTER_VALIDATE_URL. */
    #[\Override]
    public static function from(string $value): self
    {
        if ($value === '') {
            throw new \InvalidArgumentException('Url must not be empty');
        }
        $filtered = filter_var($value, FILTER_VALIDATE_URL);
        if ($filtered === false) {
            throw new \InvalidArgumentException("Invalid URL: '{$value}'");
        }
        return new self($filtered);
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
