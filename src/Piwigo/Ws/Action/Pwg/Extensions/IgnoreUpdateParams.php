<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Extensions;

use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.extensions.ignoreUpdate` input DTO. */
final readonly class IgnoreUpdateParams implements WsParams
{
    public function __construct(
        public string $pwgToken,
        public ?ExtensionType $type,
        public bool $reset,
        public string $id,
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
        $typeIn = $raw['type'] ?? null;
        $idIn   = $raw['id']   ?? null;
        return new self(
            pwgToken: $pwgToken,
            type:     is_string($typeIn) ? ExtensionType::tryFrom($typeIn) : null,
            reset:    (bool) ($raw['reset'] ?? false),
            id:       is_string($idIn) ? $idIn : '',
        );
    }
}
