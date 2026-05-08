<?php

declare(strict_types=1);

namespace Piwigo\Ws\Protocol;

use Piwigo\Ws\Encoder\PwgResponseEncoder;
use Piwigo\Ws\PwgError;

class PwgJsonEncoder extends PwgResponseEncoder
{
    public function encodeResponse(mixed $response): string
    {
        if ($response instanceof PwgError) {
            return (string) json_encode([
                'stat' => 'fail',
                'err' => $response->code(),
                'message' => $response->message(),
            ]);
        }
        parent::flattenResponse($response);
        return (string) json_encode([
            'stat' => 'ok',
            'result' => $response,
        ]);
    }

    public function getContentType(): string
    {
        return 'text/plain';
    }
}
