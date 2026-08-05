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
use Piwigo\Ws\Encoder\PwgResponseEncoder;
use Piwigo\Ws\PwgError;

final class PwgJsonEncoder extends PwgResponseEncoder
{
    #[Override]
    public function encodeResponse($response): string|false
    {
        if ($response instanceof PwgError) {
            return json_encode(
                [
                    'stat' => 'fail',
                    'err' => $response->code(),
                    'message' => $response->message(),
                ]
            );
        }
        parent::flattenResponse($response);
        return json_encode(
            [
                'stat' => 'ok',
                'result' => $response,
            ]
        );
    }

    #[Override]
    public function getContentType(): string
    {
        return 'text/plain';
    }
}
