<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.images.setMd5sum` input DTO. */
final readonly class SetMd5sumParams implements WsParams
{
    public function __construct(
        public ?int $blockSize,
        public string $pwgToken,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $pwgToken = $raw['pwg_token'] ?? null;
        if (!is_string($pwgToken)) {
            throw new WsParamException('Missing pwg_token');
        }
        return new self(
            blockSize: is_numeric($raw['block_size'] ?? null) ? (int) $raw['block_size'] : null,
            pwgToken:  $pwgToken,
        );
    }
}
