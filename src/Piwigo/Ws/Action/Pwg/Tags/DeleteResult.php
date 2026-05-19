<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Ws\WsResult;

/** `pwg.tags.delete` output DTO. */
final readonly class DeleteResult implements WsResult
{
    /** @param list<int> $deletedIds */
    public function __construct(public array $deletedIds)
    {
    }

    /** @return array{id: list<int>} */
    #[\Override]
    public function toArray(): array
    {
        return ['id' => $this->deletedIds];
    }
}
