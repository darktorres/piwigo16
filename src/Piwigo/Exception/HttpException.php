<?php

declare(strict_types=1);

namespace Piwigo\Exception;

class HttpException extends PiwigoException
{
    public function __construct(public readonly int $statusCode, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
    }
}
