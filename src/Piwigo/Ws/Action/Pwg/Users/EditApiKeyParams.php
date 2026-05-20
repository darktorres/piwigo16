<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.users.editApiKey` input DTO. */
final readonly class EditApiKeyParams implements WsParams
{
    public function __construct(
        public string $pwgToken,
        public string $pkid,
        public string $keyName,
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
        $pkidIn    = $raw['pkid']     ?? null;
        $keyNameIn = $raw['key_name'] ?? null;
        return new self(
            pwgToken: $pwgToken,
            pkid:     is_string($pkidIn) ? $pkidIn : '',
            keyName:  is_string($keyNameIn) ? $keyNameIn : '',
        );
    }
}
