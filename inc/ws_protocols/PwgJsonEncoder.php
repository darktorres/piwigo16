<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc\ws_protocols;

use Piwigo\inc\PwgError;
use Piwigo\inc\PwgResponseEncoder;

class PwgJsonEncoder extends PwgResponseEncoder
{
    public function encodeResponse($response)
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

    public function getContentType()
    {
        return 'text/plain';
    }
}
