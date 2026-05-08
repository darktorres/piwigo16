<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * PwgError object can be returned from any web service function implementation.
 * Pure value object — no side effects in the constructor. HTTP status headers
 * are applied by PwgServer::sendResponse() when the response is actually sent.
 */
class PwgError
{
    private readonly int|null $_code;
    private readonly string $_codeText;

    public function __construct(int|null $code, string $codeText)
    {
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
