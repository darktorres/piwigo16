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
 * PwgError object can be returned from any web service function implementation.
 */
final class PwgError
{
    private readonly int $_code;

    private readonly string $_codeText;

    public function __construct(
        int $code,
        string $codeText
    ) {
        if ($code >= 400 and $code < 600) {
            \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->setStatusHeader($code, $codeText);
        }

        $this->_code = $code;
        $this->_codeText = $codeText;
    }

    public function code(): int
    {
        return $this->_code;
    }

    public function message(): string
    {
        return $this->_codeText;
    }
}
