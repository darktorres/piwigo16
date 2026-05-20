<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParams;

/** `pwg.images.addChunk` input DTO. */
final readonly class AddChunkParams implements WsParams
{
    public function __construct(
        public string $originalSum,
        public int $position,
        public string $data,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $sumIn  = $raw['original_sum'] ?? null;
        $dataIn = $raw['data'] ?? null;
        return new self(
            originalSum: is_string($sumIn) ? $sumIn : '',
            position:    is_numeric($raw['position'] ?? null) ? (int) $raw['position'] : 0,
            data:        is_string($dataIn) ? $dataIn : '',
        );
    }
}
