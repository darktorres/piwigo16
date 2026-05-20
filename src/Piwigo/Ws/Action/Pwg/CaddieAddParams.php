<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg;

use Piwigo\Ws\WsParams;

/** `pwg.caddie.add` input DTO. */
final readonly class CaddieAddParams implements WsParams
{
    /** @param list<int> $imageIds */
    public function __construct(public array $imageIds)
    {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        return new self(imageIds: array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            is_array($raw['image_id'] ?? null) ? $raw['image_id'] : [],
        )));
    }
}
