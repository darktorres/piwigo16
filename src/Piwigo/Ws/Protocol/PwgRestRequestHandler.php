<?php

declare(strict_types=1);

namespace Piwigo\Ws\Protocol;

use Piwigo\Ws\PwgRequestHandler;

class PwgRestRequestHandler extends PwgRequestHandler
{
    public function handleRequest(&$service): void
    {
        $params = [];

        $param_array = $service->isPost() ? $_POST : $_GET;
        foreach ($param_array as $name => $value) {
            if ($name == 'format') {
                continue;
            } // ignore - special keys
            if ($name == 'method') {
                $method = $value;
            } else {
                $params[$name] = $value;
            }
        }
        if (empty($method) && isset($_GET['method'])) {
            $method = $_GET['method'];
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
