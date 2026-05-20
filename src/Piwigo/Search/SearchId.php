<?php

declare(strict_types=1);

namespace Piwigo\Search;

/** Typed wrapper around a saved-search identifier — either an integer id or a UUID string. */
final readonly class SearchId
{
    private function __construct(public readonly int|string $value)
    {
    }

    public static function fromInt(int $id): self
    {
        return new self($id);
    }

    public static function fromUuid(string $uuid): self
    {
        return new self($uuid);
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
