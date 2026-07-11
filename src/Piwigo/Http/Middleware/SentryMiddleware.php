<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Tracing\TransactionContext;
use function Sentry\configureScope;
use function Sentry\startTransaction;

/**
 * Wraps the request in a Sentry performance transaction. No-ops when the
 * SDK isn't initialized -- SentryBootstrap::init() only binds a client when
 * SENTRY_DSN is configured.
 */
final class SentryMiddleware implements MiddlewareInterface
{
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (SentrySdk::getCurrentHub()->getClient() === null) {
            return $handler->handle($request);
        }

        $context = new TransactionContext(
            sprintf('%s %s', $request->getMethod(), $request->getUri()->getPath())
        );
        $context->setOp('http.server');

        $transaction = startTransaction($context);
        configureScope(static function (Scope $scope) use ($transaction): void {
            $scope->setSpan($transaction);
        });

        try {
            return $handler->handle($request);
        } finally {
            $transaction->finish();
        }
    }
}
