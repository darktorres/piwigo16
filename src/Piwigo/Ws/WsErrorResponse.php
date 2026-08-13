<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Piwigo\Bootstrap\PresentationAccessor;

/**
 * WsErrorResponse object can be returned from any web service function implementation.
 */
final readonly class WsErrorResponse
{
    public function __construct(
        private readonly int $code,
        private readonly string $codeText
    ) {
        if ($code >= 400 and $code < 600) {
            PresentationAccessor::htmlService()
                ->setStatusHeader($code, $codeText);
        }
    }

    public function code(): int
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->codeText;
    }
}
