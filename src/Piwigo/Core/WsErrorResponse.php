<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Core;

/**
 * A pure {message} operation-failure value, returned from any domain
 * helper that needs to signal "this failed, here's why" without throwing
 * (`ImageFilterCriteriaBuilder::stdImageSqlFilterCriteria()`, etc.) --
 * moved here from `Piwigo\Ws` when the WS layer itself was deleted (P27);
 * every real caller is now a `Controller\Api\*` REST controller
 * pattern-matching `instanceof self` and mapping it onto
 * `ResponseFactory::problem()`, not `Ws\Server::sendResponse()` (long
 * gone). Kept its original name rather than a fresh rename -- this
 * move's real scope was already large; a `message`-only shape this
 * generic reads fine without a "Ws" prefix, but isn't actively
 * misleading enough to justify a second wide rename in the same pass.
 * The former numeric `code` (WS's own error-code taxonomy) was dropped
 * with it -- no REST caller ever read it, only `message()`.
 */
final readonly class WsErrorResponse
{
    public function __construct(
        private readonly string $codeText
    ) {}

    public function message(): string
    {
        return $this->codeText;
    }
}
