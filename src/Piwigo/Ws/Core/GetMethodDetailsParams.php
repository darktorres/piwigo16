<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Core;

use Piwigo\Ws\WsParams;

/**
 * `reflection.getMethodDetails` input DTO. `methodName` is registered as
 * mandatory (`ParamDefinition::required()`, no `WsParamType` flag), so
 * `Server::invoke()` already guarantees it's present and non-empty before
 * this method ever runs, but never coerces its type -- narrowed here
 * instead of trusting the raw request value.
 */
final readonly class GetMethodDetailsParams implements WsParams
{
    public function __construct(
        public ?string $methodName,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $methodName = $raw['methodName'] ?? null;

        return new self(
            methodName: is_string($methodName) ? $methodName : null,
        );
    }
}
