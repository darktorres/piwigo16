<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

/**
 * WsErrorResponse object can be returned from any web service function
 * implementation. A pure value -- constructing one no longer mutates
 * global response state; Server::sendResponse() (the one place that
 * builds the real Response) maps `code` onto the HTTP status itself.
 */
final readonly class WsErrorResponse
{
    public function __construct(
        private readonly int $code,
        private readonly string $codeText
    ) {}

    public function code(): int
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->codeText;
    }
}
