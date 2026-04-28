<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * PwgError object can be returned from any web service function implementation.
 */
class PwgError
{
    private int|null $_code;
    private string $_codeText;

    public function __construct(int|null $code, string $codeText)
    {
        if ($code !== null && $code >= 400 and $code < 600) {
            set_status_header($code, $codeText);
        }

        $this->_code = $code;
        $this->_codeText = $codeText;
    }

    public function code(): int|null
    {
        return $this->_code;
    }
    public function message(): string
    {
        return $this->_codeText;
    }
}
