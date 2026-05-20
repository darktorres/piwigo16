<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Core\Lang;
use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.users.revokeApiKey` input DTO. */
final readonly class RevokeApiKeyParams implements WsParams
{
    public function __construct(
        public string $pkid,
        public string $pwgToken,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $pwgToken = $raw['pwg_token'] ?? null;
        if (!is_string($pwgToken)) {
            throw new WsParamException(Lang::t('Invalid security token'));
        }
        $pkid = is_string($raw['pkid'] ?? null) ? $raw['pkid'] : '';
        if (!preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $pkid)) {
            throw new WsParamException(Lang::t('Invalid pkid format'));
        }
        return new self(pkid: $pkid, pwgToken: $pwgToken);
    }
}
