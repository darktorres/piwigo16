<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\Permalink;

/**
 * `(id, name, permalink, uppercats)` projection — the
 * CategoryNamePermalink shape plus the joined-uppercats string used by
 * the admin recent-comments page to render the breadcrumb path.
 */
final readonly class CategoryNamePermalinkUppercats
{
    public function __construct(
        public CategoryId $id,
        public string $name,
        public ?Permalink $permalink,
        public string $uppercats,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw        = $row['id'] ?? null;
        $nameRaw      = $row['name'] ?? null;
        $uppercatsRaw = $row['uppercats'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('CategoryNamePermalinkUppercats row is missing required `id` field');
        }
        return new self(
            id:        CategoryId::from((int) $idRaw),
            name:      is_string($nameRaw) ? $nameRaw : '',
            permalink: Permalink::tryFrom($row['permalink'] ?? null),
            uppercats: is_string($uppercatsRaw) ? $uppercatsRaw : '',
        );
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'id'        => $this->id->value,
            'name'      => $this->name,
            'permalink' => $this->permalink?->value,
            'uppercats' => $this->uppercats,
        ];
    }
}
