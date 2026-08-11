<?php

declare(strict_types=1);

namespace Piwigo\Tag\Projection;

use InvalidArgumentException;
use Piwigo\Common\ValueObject\TagId;

/**
 * Narrow `(id, name)` row shape for {@see \Piwigo\Tag\TagRepository::findOrphanTags()}.
 * Real caller: {@see \Piwigo\Admin\TagsPageRenderer}'s orphan-tag
 * listing/deletion flow.
 */
final readonly class TagBrief
{
    public function __construct(
        public TagId $id,
        public string $name,
    ) {}

    /**
     * @param array<string, mixed> $row a `SELECT id, name` row from `tags`
     */
    public static function fromRow(array $row): self
    {
        $id = TagId::tryFrom($row['id'] ?? null);
        if (! $id instanceof TagId) {
            throw new InvalidArgumentException(sprintf('Expected a positive tag id, got %s', get_debug_type($row['id'] ?? null)));
        }

        return new self(
            id: $id,
            name: is_string($row['name'] ?? null) ? $row['name'] : '',
        );
    }

    /**
     * @return array{id: int, name: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'name' => $this->name,
        ];
    }
}
