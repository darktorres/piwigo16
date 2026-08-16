<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Ws;

use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsParamException;

/**
 * Always throws, proving invoke()'s central WsParamException-to-403 conversion.
 */
final class ServerTestFakeWsActionThrows implements WsAction
{
    /**
     * @param array<mixed> $params
     */
    public function __invoke(array $params): never
    {
        throw new WsParamException('Bad params');
    }
}
