<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Thrown by HttpClientService when a request (or a redirect target it
 * followed) fails the SSRF guard -- non-https scheme, or a hostname that
 * resolves to a private/reserved IP range. [SEC-23]
 */
final class HttpClientSsrfException extends \RuntimeException implements RequestExceptionInterface
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
    ) {
        parent::__construct($message);
    }

    #[\Override]
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
