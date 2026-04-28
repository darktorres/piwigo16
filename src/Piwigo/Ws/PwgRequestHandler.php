<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * Abstract base class for request handlers.
 */
abstract class PwgRequestHandler
{
    /** Virtual abstract method. Decodes the request (GET or POST) handles the
     * method invocation as well as response sending.
     */
    abstract public function handleRequest(PwgServer &$service): void;
}
