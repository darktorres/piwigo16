<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * One row of `permalinks.latte`'s `$deleted_permalinks` list, built by
 * {@see \Piwigo\Controller\Admin\PermalinksSubController::handle()} from
 * a real {@see \Piwigo\Permalink\Projection\OldPermalink} plus 2 spliced
 * view-only fields (`name`, `uDelete`).
 */
final readonly class DeletedPermalinkRow
{
    public function __construct(
        public int $catId,
        public string $permalink,
        public ?string $dateDeleted,
        public ?string $lastHit,
        public int $hit,
        public string $name,
        public string $uDelete,
    ) {}

    /**
     * @return array{cat_id: int, permalink: string, date_deleted: ?string,
     *     last_hit: ?string, hit: int, name: string, U_DELETE: string}
     */
    public function toArray(): array
    {
        return [
            'cat_id' => $this->catId,
            'permalink' => $this->permalink,
            'date_deleted' => $this->dateDeleted,
            'last_hit' => $this->lastHit,
            'hit' => $this->hit,
            'name' => $this->name,
            'U_DELETE' => $this->uDelete,
        ];
    }
}
