<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Ws\WsParams;

/** `pwg.tags.getList` input DTO. */
final readonly class GetListParams implements WsParams
{
    public function __construct(public bool $sortByCounter)
    {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        return new self(sortByCounter: (bool) ($raw['sort_by_counter'] ?? false));
    }
}
