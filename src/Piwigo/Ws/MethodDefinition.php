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
 * `Server::register()`. Use the named constructors:
 *   MethodDefinition::forHandler('pwg.foo.bar', FooBarHandler::class, ...)
 *   MethodDefinition::forLegacyCallback('pwg.foo.bar', $callback, ...)
 *
 * A private constructor plus these two factories makes "exactly one of
 * handler/callback" structurally impossible to get wrong, the same
 * pattern {@see ParamDefinition} already uses. `forLegacyCallback()`
 * exists for exactly one production registration
 * (`pwg.activity.downloadLog` -- see `ActivityMethodRegistrar`'s own
 * docblock for why it deliberately stays on an untyped callback) plus
 * the Unit tests that use it as a generic `Server::invoke()` harness.
 */
final readonly class MethodDefinition
{
    /**
     * @param list<ParamDefinition> $params
     * @param class-string<WsAction>|null $handlerClass
     * @param string|array<int, string>|Closure|null $callback
     */
    private function __construct(
        public string $name,
        public ?string $handlerClass,
        public string|array|Closure|null $callback,
        public string $description,
        public array $params,
        public bool $requiresAuth,
        public bool $postOnly,
        public bool $hidden,
    ) {}

    /**
     * @param list<ParamDefinition> $params
     * @param class-string<WsAction> $handlerClass
     */
    public static function forHandler(
        string $name,
        string $handlerClass,
        string $description = '',
        array $params = [],
        bool $requiresAuth = false,
        bool $postOnly = false,
        bool $hidden = false,
    ): self {
        return new self($name, $handlerClass, null, $description, $params, $requiresAuth, $postOnly, $hidden);
    }

    /**
     * @param string|array<int, string>|Closure $callback a callable
     *   (function name, [class, method], or a first-class callable Closure)
     * @param list<ParamDefinition> $params
     */
    public static function forLegacyCallback(
        string $name,
        string|array|Closure $callback,
        string $description = '',
        array $params = [],
        bool $requiresAuth = false,
        bool $postOnly = false,
        bool $hidden = false,
    ): self {
        return new self($name, null, $callback, $description, $params, $requiresAuth, $postOnly, $hidden);
    }
}
