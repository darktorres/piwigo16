<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Activity;

use Piwigo\Ws\WsParams;

/**
 * `pwg.activity.getList` input DTO.
 *
 * `dateMin` / `dateMax` are the raw client strings — the handler still
 * needs to round-trip them through StringUtil::isValidMysqlDatetime
 * + date_create, so the DTO carries the source string.
 */
final readonly class GetListParams implements WsParams
{
    public function __construct(
        public int $offset,
        public ?int $uid,
        public ?string $action,
        public ?string $object,
        public ?int $id,
        public ?string $dateMin,
        public ?string $dateMax,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $actionIn = $raw['action'] ?? null;
        $objectIn = $raw['object'] ?? null;
        $minIn    = $raw['date_min'] ?? null;
        $maxIn    = $raw['date_max'] ?? null;
        return new self(
            offset:  is_numeric($raw['offset'] ?? null) ? (int) $raw['offset'] : 0,
            uid:     is_numeric($raw['uid']    ?? null) ? (int) $raw['uid'] : null,
            action:  is_string($actionIn) ? $actionIn : null,
            object:  is_string($objectIn) ? $objectIn : null,
            id:      is_numeric($raw['id']     ?? null) && (int) $raw['id'] !== 0 ? (int) $raw['id'] : null,
            dateMin: is_string($minIn) && $minIn !== '' ? $minIn : null,
            dateMax: is_string($maxIn) && $maxIn !== '' ? $maxIn : null,
        );
    }
}
