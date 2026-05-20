<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParams;

/** `pwg.images.formats.searchImage` input DTO — JSON candidate map. */
final readonly class FormatsSearchImageParams implements WsParams
{
    public function __construct(
        public string $filenameListJson,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $jsonIn = $raw['filename_list'] ?? null;
        return new self(filenameListJson: is_string($jsonIn) ? $jsonIn : '');
    }
}
