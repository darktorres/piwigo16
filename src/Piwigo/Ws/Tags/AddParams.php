<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Tags;

use Piwigo\Ws\WsParams;

/**
 * `pwg.tags.add` input DTO. `name` has no 'default' key -- mandatory, always present.
 */
final readonly class AddParams implements WsParams
{
    public function __construct(
        public string $name,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $name = $raw['name'] ?? null;

        return new self(
            name: is_string($name) ? $name : '',
        );
    }
}
