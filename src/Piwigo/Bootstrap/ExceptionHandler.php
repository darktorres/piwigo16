<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Core\LoggerRegistry;
use Piwigo\Exception\AuthException;
use Piwigo\Exception\HttpException;
use Piwigo\Exception\NotFoundException;
use Piwigo\Exception\ValidationException;

class ExceptionHandler
{
    public static function handle(\Throwable $e): void
    {
        if (LoggerRegistry::isInitialized()) {
            LoggerRegistry::current()->error(
                $e->getMessage(),
                ['file' => $e->getFile() . ':' . $e->getLine(), 'code' => $e->getCode()]
            );
        }

        $statusCode = 500;
        if ($e instanceof HttpException) {
            $statusCode = $e->statusCode;
        } elseif ($e instanceof AuthException) {
            $statusCode = 403;
        } elseif ($e instanceof NotFoundException) {
            $statusCode = 404;
        } elseif ($e instanceof ValidationException) {
            $statusCode = 400;
        }

        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: text/html; charset=UTF-8');
        }

        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo "<!DOCTYPE html><html><head><title>Error {$statusCode}</title></head>"
            . "<body><h1>Error {$statusCode}</h1><p>{$message}</p></body></html>";
    }

    public static function register(): void
    {
        set_exception_handler(self::handle(...));
    }
}
