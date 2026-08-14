<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Rates;

use Piwigo\Ws\WsParams;

/**
 * `pwg.rates.delete` input DTO. `user_id`: `WsParamType::ID`, no
 * 'default' key -- mandatory, always int. `anonymous_id`: no type flag,
 * null default -- always present, string|null. `image_id`:
 * `WsParamFlag::OPTIONAL` with no 'default' key -- may be entirely
 * absent; null here means "not provided or 0", matching the original's
 * `isset($params['image_id']) and $params['image_id'] !== 0` check.
 */
final readonly class DeleteParams implements WsParams
{
    public function __construct(
        public int $userId,
        public ?string $anonymousId,
        public ?int $imageId,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $userId = $raw['user_id'] ?? null;
        $anonymousId = $raw['anonymous_id'] ?? null;
        $imageId = $raw['image_id'] ?? null;

        return new self(
            userId: is_int($userId) ? $userId : 0,
            anonymousId: is_string($anonymousId) && $anonymousId !== '' ? $anonymousId : null,
            imageId: is_int($imageId) && $imageId !== 0 ? $imageId : null,
        );
    }
}
