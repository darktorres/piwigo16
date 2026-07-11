<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Sends a PSR-7 response to the PHP SAPI output layer.
 */
final class ResponseEmitter
{
    public function emit(ResponseInterface $response): void
    {
        if (! headers_sent()) {
            header(sprintf(
                'HTTP/%s %d %s',
                $response->getProtocolVersion(),
                $response->getStatusCode(),
                $response->getReasonPhrase()
            ), true, $response->getStatusCode());
            foreach ($response->getHeaders() as $name => $values) {
                $replace = true;
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), $replace);
                    $replace = false; // only replace on the first value
                }
            }
        }

        echo $response->getBody();
    }
}
