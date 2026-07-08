<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

class PwgSerialPhpEncoder extends PwgResponseEncoder
{
    public function encodeResponse($response)
    {
        if ($response instanceof PwgError) {
            return serialize(
                [
                    'stat' => 'fail',
                    'err' => $response->code(),
                    'message' => $response->message(),
                ]
            );
        }
        parent::flattenResponse($response);
        return serialize(
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
