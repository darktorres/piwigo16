<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Ws\WsResult;

/**
 * `pwg.categories.move` output DTO.
 *
 * `newArianeString` is the post-move breadcrumb (rendered HTML); the
 * admin UI swaps it into the page. `updatedCats` are per-category
 * recomputed photo counters (cat_id + nb_sub_photos) for every
 * category along the old and new ancestry chains — the admin UI
 * applies them to refresh the tree.
 */
final readonly class MoveResult implements WsResult
{
    /** @param list<array{cat_id: int, nb_sub_photos: int}> $updatedCats */
    public function __construct(
        public string $newArianeString,
        public array $updatedCats,
    ) {
    }

    /** @return array{new_ariane_string: string, updated_cats: list<array{cat_id: int, nb_sub_photos: int}>} */
    #[\Override]
    public function toArray(): array
    {
        return [
            'new_ariane_string' => $this->newArianeString,
            'updated_cats'      => $this->updatedCats,
        ];
    }
}
