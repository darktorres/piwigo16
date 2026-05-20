<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Groups;

use Piwigo\Ws\WsParams;

/** `pwg.groups.add` input DTO. */
final readonly class AddParams implements WsParams
{
    public function __construct(
        public string $name,
        public bool|string $isDefault,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $nameIn      = $raw['name'] ?? null;
        $isDefaultIn = $raw['is_default'] ?? null;
        return new self(
            name:      strip_tags(stripslashes(is_string($nameIn) ? $nameIn : '')),
            isDefault: is_bool($isDefaultIn) ? $isDefaultIn : (is_string($isDefaultIn) ? $isDefaultIn : ''),
        );
    }
}
