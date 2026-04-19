<?php

declare(strict_types=1);

namespace Piwigo\inc;

/**
 * Thrown by functions::ierror() in worker mode instead of calling exit().
 * Carries the HTTP status code and message so the worker can build a proper response.
 */
final class WorkerExitException extends \RuntimeException
{
    public function __construct(string $message, int $code)
    {
        parent::__construct($message, $code);
    }
}
