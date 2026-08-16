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
use Piwigo\Ws\Request\WsRawRequest;
use Piwigo\Ws\RequestHandler;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;
use Psr\Http\Message\ResponseInterface;

final class RestRequestHandler extends RequestHandler
{
    #[Override]
    public function handleRequest(Server $service): ResponseInterface
    {
        $wsRequest = WsRawRequest::fromGlobals();

        if ($wsRequest->method === null) {
            return $service->sendResponse(
                new WsErrorResponse(WsError::InvalidMethod->value, 'Missing "method" name')
            );
        }
        $resp = $service->invoke($wsRequest->method, $wsRequest->params);
        return $service->sendResponse($resp);
    }
}
