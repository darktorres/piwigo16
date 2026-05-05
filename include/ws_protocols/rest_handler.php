<?php

declare(strict_types=1);

use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgRequestHandler;
use Piwigo\Ws\PwgServer;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

class PwgRestRequestHandler extends PwgRequestHandler
{
    public function handleRequest(PwgServer &$service): void
    {
        $params = [];
        $method = '';

        $param_array = $service->isPost() ? $_POST : $_GET;
        foreach ($param_array as $name => $value) {
            if ($name == 'format') {
                continue;
            } // ignore - special keys
            if ($name == 'method') {
                $method = is_scalar($value) ? (string) $value : '';
            } else {
                $params[$name] = $value;
            }
        }
        if (empty($method) && isset($_GET['method'])) {
            $raw = $_GET['method'];
            $method = is_scalar($raw) ? (string) $raw : '';
        }

        if (empty($method)) {
            $service->sendResponse(
                new PwgError(WS_ERR_INVALID_METHOD, 'Missing "method" name')
            );
            return;
        }
        $resp = $service->invoke($method, $params);
        $service->sendResponse($resp);
    }
}
