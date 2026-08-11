<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Protocol;

use Override;
use Piwigo\Core\WsError;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\Request\WsRawRequest;
use Piwigo\Ws\RequestHandler;
use Piwigo\Ws\Server;

final class RestRequestHandler extends RequestHandler
{
    #[Override]
    public function handleRequest(Server &$service): void
    {
        $wsRequest = WsRawRequest::fromGlobals();

        if ($wsRequest->method === null) {
            $service->sendResponse(
                new PwgError(WsError::INVALID_METHOD, 'Missing "method" name')
            );
            return;
        }
        $resp = $service->invoke($wsRequest->method, $wsRequest->params);
        $service->sendResponse($resp);
    }
}
