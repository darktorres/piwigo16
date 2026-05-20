<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParams;

/** `pwg.images.checkFiles` input DTO. */
final readonly class CheckFilesParams implements WsParams
{
    public function __construct(
        public int $imageId,
        public ?string $fileSum,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $fileSumIn = $raw['file_sum'] ?? null;
        return new self(
            imageId: is_numeric($raw['image_id'] ?? null) ? (int) $raw['image_id'] : 0,
            fileSum: is_string($fileSumIn) ? $fileSumIn : null,
        );
    }
}
