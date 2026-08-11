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
 * PwgError object can be returned from any web service function implementation.
 */
final class PwgError
{
    private readonly int $code;

    private readonly string $codeText;

    public function __construct(
        int $code,
        string $codeText
    ) {
        if ($code >= 400 and $code < 600) {
            PresentationAccessor::htmlService()
                ->setStatusHeader($code, $codeText);
        }

        $this->code = $code;
        $this->codeText = $codeText;
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
