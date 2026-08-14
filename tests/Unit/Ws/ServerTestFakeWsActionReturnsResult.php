<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Ws;

use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsResult;

/**
 * Always returns a WsResult, proving invoke() unwraps it via toArray().
 */
final class ServerTestFakeWsActionReturnsResult implements WsAction
{
    /**
     * @param array<mixed> $params
     */
    public function __invoke(array $params, Server $server): WsResult
    {
        return new class() implements WsResult {
            /**
             * @return array<string, mixed>
             */
            public function toArray(): array
            {
                return [
                    'wrapped' => true,
                ];
            }
        };
    }
}
