<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryService::getCategoryInfo()}'s own fixed
 * result shape -- {@see Category::toArray()}'s shape plus `upperNames`
 * (the breadcrumb trail).
 */
final readonly class CategoryInfo
{
    /**
     * @param list<CategoryIdNamePermalink> $upperNames
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?int $idUppercat,
        public ?string $comment,
        public ?string $dir,
        public ?int $rank,
        public string $status,
        public ?int $siteId,
        public bool $visible,
        public ?int $representativePictureId,
        public string $uppercats,
        public bool $commentable,
        public ?string $globalRank,
        public ?string $imageOrder,
        public ?string $permalink,
        public string $lastmodified,
        public array $upperNames,
    ) {}

    /**
     * {@see \Piwigo\Section\SectionContext::toUrlParams()} is the real
     * boundary that needs this -- {@see \Piwigo\Url\UrlService}'s own
     * make*Url() `$params['category']`/`$params['combined_categories']`
     * stay a generic array by design (a widely-callable public API taking
     * arbitrary caller-composed redefinition arrays, not just this class's
     * own instances). `SectionPopulator`/`SectionContext` themselves carry
     * this object directly, unboxed nowhere else.
     *
     * @return array{id: int, name: string, id_uppercat: ?int, comment: ?string,
     *   dir: ?string, rank: ?int, status: string, site_id: ?int, visible: bool,
     *   representative_picture_id: ?int, uppercats: string, commentable: bool,
     *   global_rank: ?string, image_order: ?string, permalink: ?string, lastmodified: string,
     *   upper_names: list<array{id: int, name: string, permalink: ?string}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'id_uppercat' => $this->idUppercat,
            'comment' => $this->comment,
            'dir' => $this->dir,
            'rank' => $this->rank,
            'status' => $this->status,
            'site_id' => $this->siteId,
            'visible' => $this->visible,
            'representative_picture_id' => $this->representativePictureId,
            'uppercats' => $this->uppercats,
            'commentable' => $this->commentable,
            'global_rank' => $this->globalRank,
            'image_order' => $this->imageOrder,
            'permalink' => $this->permalink,
            'lastmodified' => $this->lastmodified,
            'upper_names' => array_map(static fn (CategoryIdNamePermalink $row): array => $row->toArray(), $this->upperNames),
        ];
    }
}
