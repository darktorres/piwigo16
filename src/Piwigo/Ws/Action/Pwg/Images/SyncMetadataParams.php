<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/**
 * `pwg.images.syncMetadata` input DTO.
 *
 * `imageIds` is the trimmed token list; the handler still validates
 * each token against ValidationPattern::ID so it can emit per-token
 * error messages.
 */
final readonly class SyncMetadataParams implements WsParams
{
    /** @param list<string> $imageIds */
    public function __construct(
        public string $pwgToken,
        public array $imageIds,
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
        $rawIds = $raw['image_id'] ?? null;
        if (is_array($rawIds)) {
            $tokens = $rawIds;
        } else {
            $split  = preg_split('/[\s,;\|]/', is_scalar($rawIds) ? (string) $rawIds : '', -1, PREG_SPLIT_NO_EMPTY);
            $tokens = $split !== false ? $split : [];
        }
        $strings = [];
        foreach ($tokens as $tok) {
            $strings[] = trim(is_scalar($tok) ? (string) $tok : '');
        }
        return new self(pwgToken: $pwgToken, imageIds: $strings);
    }
}
