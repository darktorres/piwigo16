<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Groups;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/**
 * `pwg.groups.setInfo` input DTO.
 *
 * `isDefault` keeps the original `is_string|is_bool|null` triple so the
 * handler can still distinguish "absent" from "false" (the legacy
 * call accepted `is_default=false` as an explicit reset).
 */
final readonly class SetInfoParams implements WsParams
{
    public function __construct(
        public string $pwgToken,
        public int $groupId,
        public ?string $name,
        public bool|string|null $isDefault,
        public bool $isDefaultSet,
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
        $nameIn       = $raw['name'] ?? null;
        $isDefaultIn  = $raw['is_default'] ?? null;
        $isDefaultSet = array_key_exists('is_default', $raw);
        $isDefault    = is_bool($isDefaultIn)
            ? $isDefaultIn
            : (is_string($isDefaultIn) ? $isDefaultIn : null);
        return new self(
            pwgToken:     $pwgToken,
            groupId:      is_numeric($raw['group_id'] ?? null) ? (int) $raw['group_id'] : 0,
            name:         is_string($nameIn) ? $nameIn : null,
            isDefault:    $isDefault,
            isDefaultSet: $isDefaultSet,
        );
    }
}
