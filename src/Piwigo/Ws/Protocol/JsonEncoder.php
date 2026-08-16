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
use Piwigo\Ws\Encoder\ResponseEncoder;
use Piwigo\Ws\WsErrorResponse;

final class JsonEncoder extends ResponseEncoder
{
    #[Override]
    public function encodeResponse(mixed $response): string
    {
        if ($response instanceof WsErrorResponse) {
            return json_encode(
                [
                    'stat' => 'fail',
                    'err' => $response->code(),
                    'message' => $response->message(),
                ],
                JSON_THROW_ON_ERROR
            );
        }
        parent::flattenResponse($response);
        return json_encode(
            [
                'stat' => 'ok',
                'result' => $response,
            ],
            JSON_THROW_ON_ERROR
        );
    }

    #[Override]
    public function getContentType(): string
    {
        return 'application/json';
    }
}
