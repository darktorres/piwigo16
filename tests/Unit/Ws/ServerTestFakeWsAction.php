<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Ws;

use Piwigo\Ws\WsAction;

/**
 * Echoes back its checked params, same shape as ServerTest.php's own
 * "invoke calls the real registered callback" test (registered via
 * MethodDefinition::forLegacyCallback()) -- proves invoke()'s
 * handlerClass branch resolves and calls a real container-autowired
 * WsAction.
 */
final class ServerTestFakeWsAction implements WsAction
{
    /**
     * @param array<mixed> $params
     * @return array{echo: array<mixed>}
     */
    public function __invoke(array $params): array
    {
        return [
            'echo' => $params,
        ];
    }
}
