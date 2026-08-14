<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

/**
 * Typed descriptor for a WS method registration, passed to
 * `Server::register()`. Coexists with the still-unchanged
 * `Server::addMethod()` (the legacy callback-based path) so each domain
 * batch can migrate one WS method at a time instead of one atomic
 * cutover of all registrations.
 */
final readonly class MethodDefinition
{
    /**
     * @param list<ParamDefinition> $params
     * @param class-string<WsAction> $handlerClass
     */
    public function __construct(
        public string $name,
        public string $handlerClass,
        public string $description = '',
        public array $params = [],
        public bool $requiresAuth = false,
        public bool $postOnly = false,
        public bool $hidden = false,
    ) {}
}
