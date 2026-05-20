<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParams;

/**
 * `pwg.images.search` input DTO.
 *
 * The raw `$params` still passes through to WsHelper for sort/filter
 * clauses — only the discrete fields are pulled into the DTO.
 */
final readonly class SearchParams implements WsParams
{
    public function __construct(
        public string $query,
        public int $page,
        public int $perPage,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $queryIn = $raw['query'] ?? null;
        return new self(
            query:   is_string($queryIn) ? $queryIn : '',
            page:    is_numeric($raw['page']     ?? null) ? (int) $raw['page'] : 0,
            perPage: is_numeric($raw['per_page'] ?? null) ? (int) $raw['per_page'] : 0,
        );
    }
}
