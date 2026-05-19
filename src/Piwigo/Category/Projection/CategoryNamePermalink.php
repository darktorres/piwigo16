<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\Permalink;

/**
 * Narrow `(id, name, permalink)` projection used wherever a caller needs
 * to render a category link without loading the full row (upper-name
 * breadcrumb, picture-page "in category X" pill, etc.).
 */
final readonly class CategoryNamePermalink
{
    public function __construct(
        public CategoryId $id,
        public string $name,
        public ?Permalink $permalink,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw   = $row['id'] ?? null;
        $nameRaw = $row['name'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('CategoryNamePermalink row is missing required `id` field');
        }
        return new self(
            id:        CategoryId::from((int) $idRaw),
            name:      is_string($nameRaw) ? $nameRaw : '',
            permalink: Permalink::tryFrom($row['permalink'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'id'        => $this->id->value,
            'name'      => $this->name,
            'permalink' => $this->permalink?->value,
        ];
    }
}
