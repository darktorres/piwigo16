<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * PwgError object can be returned from any web service function implementation.
 */
class PwgError
{
    private $_code;
    private $_codeText;

    public function __construct($code, $codeText)
    {
        if ($code >= 400 and $code < 600) {
            set_status_header($code, $codeText);
        }

        $this->_code = $code;
        $this->_codeText = $codeText;
    }

    public function code()
    {
        return $this->_code;
    }
    public function message()
    {
        return $this->_codeText;
    }
}
