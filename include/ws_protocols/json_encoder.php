<?php

declare(strict_types=1);

use Piwigo\Ws\Encoder\PwgResponseEncoder;
use Piwigo\Ws\PwgError;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

class PwgJsonEncoder extends PwgResponseEncoder
{
    public function encodeResponse(mixed $response): mixed
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

    public function getContentType(): string
    {
        return 'text/plain';
    }
}
