<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Ws;

use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;

/**
 * Echoes back its checked params plus a same-instance proof, same shape
 * as ServerTest.php's own addMethod-based "invoke calls the real
 * registered callback" test -- proves invoke()'s handlerClass branch
 * resolves and calls a real container-autowired WsAction the same way.
 */
final class ServerTestFakeWsAction implements WsAction
{
    /**
     * @param array<mixed> $params
     * @return array{echo: array<mixed>, sameService: bool}
     */
    public function __invoke(array $params, Server $server): array
    {
        return [
            'echo' => $params,
            'sameService' => $server->hasMethod('test.handlerClass'),
        ];
    }
}
