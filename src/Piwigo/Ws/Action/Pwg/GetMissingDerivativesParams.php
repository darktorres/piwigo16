<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg;

use Piwigo\Image\ImageStdParams;
use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/**
 * `pwg.getMissingDerivatives` input DTO.
 */
final readonly class GetMissingDerivativesParams implements WsParams
{
    /**
     * @param list<string> $types       derivative-size codes (default = all defined)
     * @param list<int>    $imageIds    optional restriction to these image ids
     * @param int          $maxUrls     pagination cap on the URLs list (0 = no cap from input)
     * @param int          $prevPageCursor  pagination cursor — the last image id from the previous page (0 = start)
     */
    public function __construct(
        public array $types,
        public array $imageIds,
        public int $maxUrls,
        public int $prevPageCursor,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $definedTypes = array_keys(ImageStdParams::getDefinedTypeMap());
        if (empty($raw['types'])) {
            $types = $definedTypes;
        } else {
            $typesRaw = is_array($raw['types']) ? $raw['types'] : [];
            $types = array_values(array_intersect(
                $definedTypes,
                array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $typesRaw),
            ));
            if ($types === []) {
                throw new WsParamException('Invalid types');
            }
        }
        $idsRaw = is_array($raw['ids'] ?? null) ? $raw['ids'] : [];
        return new self(
            types:           $types,
            imageIds:        array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $idsRaw)),
            maxUrls:         is_numeric($raw['max_urls']  ?? null) ? (int) $raw['max_urls'] : 0,
            prevPageCursor:  is_numeric($raw['prev_page'] ?? null) ? (int) $raw['prev_page'] : 0,
        );
    }
}
