<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Extensions;

use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/**
 * `pwg.extensions.update` input DTO.
 *
 * `reactivate` is a flag that the handler re-injects through a self-
 * redirect (the deactivate-update-reactivate dance for plugin upgrades).
 */
final readonly class UpdateParams implements WsParams
{
    public function __construct(
        public string $pwgToken,
        public ExtensionType $type,
        public string $id,
        public string $revision,
        public bool $reactivate,
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
        $typeIn  = $raw['type'] ?? null;
        $idIn    = $raw['id']   ?? null;
        $revIn   = $raw['revision'] ?? null;
        $type    = is_string($typeIn) ? ExtensionType::tryFrom($typeIn) : null;
        if ($type === null) {
            throw new WsParamException('invalid extension type');
        }
        return new self(
            pwgToken:   $pwgToken,
            type:       $type,
            id:         is_string($idIn) ? $idIn : '',
            revision:   is_string($revIn) ? $revIn : '',
            reactivate: array_key_exists('reactivate', $raw),
        );
    }
}
