<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Protocol;

use Piwigo\Core\WsError;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgRequestHandler;
use Piwigo\Ws\PwgServer;

class PwgRestRequestHandler extends PwgRequestHandler
{
    #[\Override]
    public function handleRequest(PwgServer &$service): void
    {
        $params = [];

        $param_array = PwgServer::isPost() ? $_POST : $_GET;
        foreach ($param_array as $name => $value) {
            if ($name === 'format') {
                continue;
            } // ignore - special keys
            if ($name === 'method') {
                $method = $value;
            } else {
                $params[(string) $name] = $value;
            }
        }
        if ((! isset($method) || (is_string($method) && ($method === '' || $method === '0'))) && isset($_GET['method'])) {
            $method = $_GET['method'];
        }

        if (! isset($method) || ! is_string($method) || $method === '' || $method === '0') {
            $service->sendResponse(
                new PwgError(WsError::INVALID_METHOD, 'Missing "method" name')
            );
            return;
        }
        $resp = $service->invoke($method, $params);
        $service->sendResponse($resp);
    }
}
