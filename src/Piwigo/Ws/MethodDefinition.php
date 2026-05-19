<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * Typed descriptor for a WS method registration.
 *
 * Pass to PwgServer::register(). The server stores the MethodDefinition for
 * SpecBuilder / #22 and normalizes params to the internal WsParamDef array
 * so invoke() works unchanged.
 *
 * Example:
 *   $server->register(new MethodDefinition(
 *       name:        'pwg.images.getInfo',
 *       callback:    ImagesEndpoints::getInfo(...),
 *       description: 'Returns information about an image.',
 *       params: [
 *           ParamDefinition::required('image_id', WsType::Id->value),
 *           ParamDefinition::optional('comments_page', 0, WsType::Int->value | WsType::Positive->value),
 *       ],
 *       tags:        ['images'],
 *       requiresAuth: false,
 *   ));
 */
final readonly class MethodDefinition
{
    /**
     * Methods register through either of two paths:
     *
     * - **Legacy `$callback`** — closure or `Endpoints::method(...)` slot,
     *   captured at registration time. The historical pre-F5 pattern; the
     *   *Endpoints god-classes are migrating off this path through F5-h.
     * - **`$handlerClass`** (F5-h) — class-string of a {@see WsAction}
     *   that PwgServer resolves from the container at invocation time.
     *   Each handler lives in its own file and gets only the dependencies
     *   the endpoint needs.
     *
     * Exactly one of `$callback` / `$handlerClass` must be set; the second
     * registration path replaces the first one endpoint at a time.
     *
     * @param  list<ParamDefinition>      $params
     * @param  list<string>               $tags
     * @param  class-string<WsAction>|null $handlerClass
     */
    public function __construct(
        public string $name,
        public mixed $callback = null,
        public string $description = '',
        public array $params = [],
        public string $returns = '',
        public array $tags = [],
        public bool $requiresAuth = false,
        public bool $postOnly = false,
        public bool $hidden = false,
        public ?string $handlerClass = null,
    ) {
        if ($callback === null && $handlerClass === null) {
            throw new \InvalidArgumentException(
                "MethodDefinition {$name} must declare either a callback or a handlerClass"
            );
        }
        if ($callback !== null && $handlerClass !== null) {
            throw new \InvalidArgumentException(
                "MethodDefinition {$name} must not declare both callback and handlerClass"
            );
        }
    }
}
