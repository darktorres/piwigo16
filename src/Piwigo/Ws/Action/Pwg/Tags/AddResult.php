<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Ws\WsResult;

/** `pwg.tags.add` output DTO. */
final readonly class AddResult implements WsResult
{
    public function __construct(
        public string $info,
        public int $id,
        public string $name,
        public string $urlName,
    ) {
    }

    /** @return array{info: string, id: int, name: string, url_name: string} */
    #[\Override]
    public function toArray(): array
    {
        return [
            'info'     => $this->info,
            'id'       => $this->id,
            'name'     => $this->name,
            'url_name' => $this->urlName,
        ];
    }
}
