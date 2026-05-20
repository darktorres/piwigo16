<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.images.deleteOrphans` input DTO. */
final readonly class DeleteOrphansParams implements WsParams
{
    public function __construct(
        public string $pwgToken,
        public ?int $blockSize,
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
            pwgToken:  $pwgToken,
            blockSize: is_numeric($raw['block_size'] ?? null) ? (int) $raw['block_size'] : null,
        );
    }
}
