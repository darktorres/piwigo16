<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Ws\WsResult;

/** `pwg.tags.duplicate` output DTO. */
final readonly class DuplicateResult implements WsResult
{
    public function __construct(
        public int $id,
        public string $name,
        public string $urlName,
        public int $count,
    ) {
    }

    /** @return array{id: int, name: string, url_name: string, count: int} */
    #[\Override]
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'url_name' => $this->urlName,
            'count'    => $this->count,
        ];
    }
}
