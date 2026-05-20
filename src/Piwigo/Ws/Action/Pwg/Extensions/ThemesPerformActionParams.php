<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Extensions;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.extensions.themes.performAction` input DTO. */
final readonly class ThemesPerformActionParams implements WsParams
{
    public function __construct(
        public string $pwgToken,
        public string $action,
        public string $theme,
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
        $actionIn = $raw['action'] ?? null;
        $themeIn  = $raw['theme']  ?? null;
        return new self(
            pwgToken: $pwgToken,
            action:   is_string($actionIn) ? $actionIn : '',
            theme:    is_string($themeIn) ? $themeIn : '',
        );
    }
}
