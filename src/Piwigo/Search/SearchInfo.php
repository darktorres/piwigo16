<?php

declare(strict_types=1);

namespace Piwigo\Search;

/** Projection of a saved-search row: its identifier, the search it was forked from, and the raw rules JSON. */
final readonly class SearchInfo
{
    public function __construct(
        public SearchId $id,
        public ?SearchId $forkedFrom,
        public string $rulesJson,
    ) {
    }
}
