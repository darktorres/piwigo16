<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * Typed descriptor for a WS method registration.
 *
 * Pass to PwgServer::register() as the new typed path alongside the legacy
 * addMethod() array-based path. The legacy addMethod() still works unchanged;
 * register() stores both the MethodDefinition (for SpecBuilder / #22) and
 * a normalized WsParamDef array (for invoke() / existing BC code).
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
final class MethodDefinition
{
    /**
     * @param list<ParamDefinition> $params
     * @param list<string>          $tags
     */
    public function __construct(
        public readonly string $name,
        public readonly mixed $callback,
        public readonly string $description = '',
        public readonly array $params = [],
        public readonly string $returns = '',
        public readonly array $tags = [],
        public readonly bool $requiresAuth = false,
        public readonly bool $postOnly = false,
        public readonly bool $hidden = false,
        public readonly string $includeFile = '',
    ) {
    }
}
