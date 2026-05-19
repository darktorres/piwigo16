<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.tags.add` input DTO. */
final readonly class AddParams implements WsParams
{
    public function __construct(public string $name)
    {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $name = $raw['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new WsParamException('Missing or invalid `name`');
        }
        return new self($name);
    }
}
