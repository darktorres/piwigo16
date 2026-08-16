<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Psr\Http\Message\ResponseInterface;

/**
 * Abstract base class for request handlers.
 */
abstract class RequestHandler
{
    /** Virtual abstract method. Decodes the request (GET or POST), handles the
     * method invocation, and returns the real response.
     */
    abstract public function handleRequest(Server $service): ResponseInterface;
}
