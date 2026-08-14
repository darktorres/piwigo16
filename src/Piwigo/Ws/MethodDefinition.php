<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Closure;

/**
 * Typed descriptor for a WS method registration, passed to
 * `Server::register()`. Exactly one of `$callback`/`$handlerClass` must
 * be set -- the dual-path design (alongside the still-unchanged
 * `Server::addMethod()`) lets each domain batch migrate one WS method at
 * a time instead of one atomic cutover of all registrations.
 */
final readonly class MethodDefinition
{
    /**
     * @param string|array<int, string>|Closure|null $callback
     * @param list<ParamDefinition> $params
     * @param list<string> $tags
     * @param class-string<WsAction>|null $handlerClass
     */
    public function __construct(
        public string $name,
        public string|array|Closure|null $callback = null,
        public string $description = '',
        public array $params = [],
        public array $tags = [],
        public bool $requiresAuth = false,
        public bool $postOnly = false,
        public bool $hidden = false,
        public ?string $handlerClass = null,
    ) {
        if ($callback === null and $handlerClass === null) {
            throw new \InvalidArgumentException("MethodDefinition {$name} must declare either a callback or a handlerClass");
        }
        if ($callback !== null and $handlerClass !== null) {
            throw new \InvalidArgumentException("MethodDefinition {$name} must not declare both callback and handlerClass");
        }
    }
}
