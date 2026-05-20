<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Config\Config;
use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.images.setPrivacyLevel` input DTO. */
final readonly class SetPrivacyLevelParams implements WsParams
{
    /** @param list<int> $imageIds */
    public function __construct(
        public int $level,
        public array $imageIds,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        if (!in_array($raw['level'] ?? null, Config::availablePermissionLevels())) {
            throw new WsParamException('Invalid level');
        }
        return new self(
            level:    is_numeric($raw['level']) ? (int) $raw['level'] : 0,
            imageIds: array_values(array_map(
                static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
                is_array($raw['image_id'] ?? null) ? $raw['image_id'] : [],
            )),
        );
    }
}
