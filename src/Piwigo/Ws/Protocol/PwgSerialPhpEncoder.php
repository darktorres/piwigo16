<?php

declare(strict_types=1);

namespace Piwigo\Ws\Protocol;

use Piwigo\Ws\Encoder\PwgResponseEncoder;

class PwgSerialPhpEncoder extends PwgResponseEncoder
{
    public function encodeResponse($response): string
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

    public function getContentType(): string
    {
        return 'text/plain';
    }
}
