<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * PwgError object can be returned from any web service function implementation.
 */
final class PwgError
{
    private int $_code;

    private string $_codeText;

    public function __construct(
        ?int $code,
        array|string $codeText
    ) {
        if ($code >= 400 and
            $code < 600
        ) {
            functions_html::set_status_header($code, $codeText);
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
