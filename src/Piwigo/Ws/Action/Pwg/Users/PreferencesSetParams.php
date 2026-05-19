<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.users.preferencesSet` input DTO. */
final readonly class PreferencesSetParams implements WsParams
{
    public function __construct(
        public string $param,
        public string $value,
        public bool $isJson,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $param = is_string($raw['param'] ?? null) ? $raw['param'] : '';
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $param)) {
            throw new WsParamException('Invalid param name #' . $param . '#');
        }
        return new self(
            param:  $param,
            value:  is_string($raw['value'] ?? null) ? $raw['value'] : '',
            isJson: (bool) ($raw['is_json'] ?? false),
        );
    }
}
