<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParams;

/**
 * `pwg.images.filteredSearch.create` input DTO.
 *
 * Only typed extraction for the discrete-string fields + the
 * search_id reload key. The rich filter-field parsing (allwords,
 * tags, dates, ratios, …) stays in the handler because each sub-shape
 * has its own validation error to emit.
 */
final readonly class FilteredSearchCreateParams implements WsParams
{
    public function __construct(public ?string $searchId)
    {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $searchIdIn = $raw['search_id'] ?? null;
        return new self(searchId: is_string($searchIdIn) && $searchIdIn !== '' ? $searchIdIn : null);
    }
}
