<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.images.uploadCompleted` input DTO. */
final readonly class UploadCompletedParams implements WsParams
{
    /** @param list<int> $imageIds */
    public function __construct(
        public string $pwgToken,
        public array $imageIds,
        public int $categoryId,
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
        $imageIds = array_values(array_filter(
            array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $tokens),
            static fn (int $v): bool => $v > 0,
        ));
        return new self(
            pwgToken:   $pwgToken,
            imageIds:   $imageIds,
            categoryId: is_numeric($raw['category_id'] ?? null) ? (int) $raw['category_id'] : 0,
        );
    }
}
