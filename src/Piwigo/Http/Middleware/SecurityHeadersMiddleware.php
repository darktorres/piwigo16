<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Piwigo\Http\BaselineSecurityHeaders;
use Piwigo\Http\SecurityHeaderContributor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * @param list<SecurityHeaderContributor> $contributors
     */
    public function __construct(
        private array $contributors = [new BaselineSecurityHeaders(),
        ]
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        foreach ($this->contributors as $contributor) {
            $response = $contributor->contribute($response);
        }

        return $response;
    }
}
