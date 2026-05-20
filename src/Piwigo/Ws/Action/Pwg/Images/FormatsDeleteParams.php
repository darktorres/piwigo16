<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.images.formats.delete` input DTO. */
final readonly class FormatsDeleteParams implements WsParams
{
    /** @param list<int<0, max>> $formatIds */
    public function __construct(
        public string $pwgToken,
        public array $formatIds,
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
        $rawIds = $raw['format_id'] ?? null;
        if (is_array($rawIds)) {
            $tokens = $rawIds;
        } else {
            $split  = preg_split('/[\s,;\|]/', is_string($rawIds) ? $rawIds : '', -1, PREG_SPLIT_NO_EMPTY);
            $tokens = $split !== false ? $split : [];
        }
        $ints      = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $tokens);
        /** @var list<int<0, max>> $formatIds */
        $formatIds = array_values(array_filter($ints, static fn (int $v): bool => $v >= 0));
        return new self(pwgToken: $pwgToken, formatIds: $formatIds);
    }
}
