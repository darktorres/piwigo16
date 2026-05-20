<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.images.delete` input DTO — list/separator-string of image ids + csrf token. */
final readonly class DeleteParams implements WsParams
{
    /** @param list<int<1, max>> $imageIds */
    public function __construct(
        public array $imageIds,
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
        $rawImageIds = $raw['image_id'] ?? null;
        if (is_array($rawImageIds)) {
            $imageIdList = $rawImageIds;
        } elseif (is_scalar($rawImageIds)) {
            $split       = preg_split('/[\s,;\|]/', (string) $rawImageIds, -1, PREG_SPLIT_NO_EMPTY);
            $imageIdList = $split !== false ? $split : [];
        } else {
            $imageIdList = [];
        }
        $imageIds = array_values(array_filter(
            array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $imageIdList),
            static fn (int $v): bool => $v > 0,
        ));
        return new self(imageIds: $imageIds, pwgToken: $pwgToken);
    }
}
