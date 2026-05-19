<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Common\Enum\Privacy;
use Piwigo\Common\ValueObject\CategoryId;

/**
 * `(id, uppercats, commentable, visible, status, global_rank)` projection
 * — categories the current picture belongs to, joined for the picture-page
 * "related categories" navigation strip.
 */
final readonly class PictureNavCategoryRow
{
    public function __construct(
        public CategoryId $id,
        public string $uppercats,
        public bool $commentable,
        public bool $visible,
        public Privacy $status,
        public ?string $globalRank,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw          = $row['id'] ?? null;
        $uppercatsRaw   = $row['uppercats'] ?? null;
        $commentableRaw = $row['commentable'] ?? false;
        $visibleRaw     = $row['visible'] ?? true;
        $statusRaw      = $row['status'] ?? null;
        $globalRankRaw  = $row['global_rank'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('PictureNavCategoryRow is missing required `id` field');
        }
        return new self(
            id:          CategoryId::from((int) $idRaw),
            uppercats:   is_string($uppercatsRaw) ? $uppercatsRaw : '',
            commentable: is_bool($commentableRaw) ? $commentableRaw : (is_numeric($commentableRaw) ? (int) $commentableRaw !== 0 : false),
            visible:     is_bool($visibleRaw) ? $visibleRaw : (is_numeric($visibleRaw) ? (int) $visibleRaw !== 0 : true),
            status:      is_string($statusRaw) ? Privacy::from($statusRaw) : Privacy::Public,
            globalRank:  is_string($globalRankRaw) ? $globalRankRaw : null,
        );
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'id'          => $this->id->value,
            'uppercats'   => $this->uppercats,
            'commentable' => $this->commentable ? 1 : 0,
            'visible'     => $this->visible ? 1 : 0,
            'status'      => $this->status->value,
            'global_rank' => $this->globalRank,
        ];
    }
}
