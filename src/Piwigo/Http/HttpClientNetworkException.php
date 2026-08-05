<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Override;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * Wraps a Symfony transport-level failure (DNS resolution, connection,
 * TLS, timeout) as a PSR-18 NetworkExceptionInterface.
 */
final class HttpClientNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    #[Override]
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
