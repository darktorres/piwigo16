<?php

declare(strict_types=1);

namespace Piwigo\Ws\Protocol;

use Piwigo\Ws\Encoder\PwgResponseEncoder;
use Piwigo\Ws\PwgError;

final class PwgSerialPhpEncoder extends PwgResponseEncoder
{
    #[\Override]
    public function encodeResponse(mixed $response): string
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

    #[\Override]
    public function getContentType(): string
    {
        return 'text/plain';
    }
}
