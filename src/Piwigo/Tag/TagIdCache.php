<?php

declare(strict_types=1);

namespace Piwigo\Tag;

use Piwigo\Common\ValueObject\TagId;

/**
 * Minimal mutable box for TagService::tagIdFromTagName()'s per-instance
 * memoization. TagService itself is `readonly` (no mutable instance or
 * static properties allowed there), so the cache needs to live behind an
 * object whose own reference stays fixed while its internal array mutates
 * -- `readonly` only forbids reassigning the property, not mutating the
 * object it points to.
 */
final class TagIdCache
{
    /**
     * @var array<string, TagId>
     */
    private array $ids = [];

    public function get(string $tagName): ?TagId
    {
        return $this->ids[$tagName] ?? null;
    }

    public function set(string $tagName, TagId $id): void
    {
        $this->ids[$tagName] = $id;
    }
}
