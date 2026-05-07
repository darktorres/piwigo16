<?php

declare(strict_types=1);

namespace Piwigo\Ws;

use Piwigo\Core\ServiceLocator;
use Piwigo\Html\HtmlService;

/**
 * PwgError object can be returned from any web service function implementation.
 */
class PwgError
{
    private readonly int|null $_code;
    private readonly string $_codeText;

    public function __construct(int|null $code, string $codeText)
    {
        if ($code !== null && $code >= 400 and $code < 600) {
            ServiceLocator::get(HtmlService::class)->setStatusHeader($code, $codeText);
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
