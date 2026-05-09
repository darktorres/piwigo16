<?php

declare(strict_types=1);

namespace Piwigo\Exception;

final class HttpException extends PiwigoException
{
    public function __construct(public readonly int $statusCode, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
    }
}
