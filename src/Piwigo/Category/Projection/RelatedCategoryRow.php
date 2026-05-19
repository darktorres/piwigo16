<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\Permalink;

/**
 * Projection of a `categories` row joined with `image_category` for the
 * "related categories" panel on the picture page / `pwg.images.getInfo`
 * response. The `commentable` column is fetched but never shipped back
 * — it only feeds the page-level `comment_post` block.
 */
final readonly class RelatedCategoryRow
{
    public function __construct(
        public CategoryId $id,
        public string $name,
        public ?Permalink $permalink,
        public string $uppercats,
        public ?string $globalRank,
        public bool $commentable,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw          = $row['id'] ?? null;
        $nameRaw        = $row['name'] ?? null;
        $uppercatsRaw   = $row['uppercats'] ?? null;
        $globalRankRaw  = $row['global_rank'] ?? null;
        $commentableRaw = $row['commentable'] ?? false;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('RelatedCategoryRow is missing required `id` field');
        }
        return new self(
            id:          CategoryId::from((int) $idRaw),
            name:        is_string($nameRaw) ? $nameRaw : '',
            permalink:   Permalink::tryFrom($row['permalink'] ?? null),
            uppercats:   is_string($uppercatsRaw) ? $uppercatsRaw : '',
            globalRank:  is_string($globalRankRaw) ? $globalRankRaw : null,
            commentable: is_bool($commentableRaw) ? $commentableRaw : (is_numeric($commentableRaw) ? (int) $commentableRaw !== 0 : false),
        );
    }

    /**
     * Emit the legacy `categories`-row shape consumed by makeIndexUrl /
     * makePictureUrl (omits `commentable`).
     *
     * @return array<string, mixed>
     */
    public function toUrlRow(): array
    {
        return [
            'id'          => $this->id->value,
            'name'        => $this->name,
            'permalink'   => $this->permalink?->value,
            'uppercats'   => $this->uppercats,
            'global_rank' => $this->globalRank,
        ];
    }
}
