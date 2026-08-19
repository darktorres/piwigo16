<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * One row of `permalinks.latte`'s `$permalinks` list, built by
 * {@see \Piwigo\Controller\Admin\PermalinksSubController::handle()} from
 * a real {@see \Piwigo\Category\Projection\ActivePermalinkRow} plus one
 * spliced view-only field (`name`, the resolved display name). Drops
 * `ActivePermalinkRow`'s own `uppercats`/`globalRank` -- both are used
 * only for sorting before this row is built (`CategoryService::
 * compareByGlobalRank()`), never read by `permalinks.latte` itself
 * (confirmed: only `id`/`name`/`permalink` appear in that template).
 */
final readonly class PermalinkListRow
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $permalink,
    ) {}

    /**
     * @return array{id: int, name: string, permalink: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'permalink' => $this->permalink,
        ];
    }
}
