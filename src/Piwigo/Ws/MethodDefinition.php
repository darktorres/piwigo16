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
     * @param list<ParamDefinition> $params
     * @param list<string>          $tags
     */
    public function __construct(
        public string $name,
        public mixed $callback,
        public string $description = '',
        public array $params = [],
        public string $returns = '',
        public array $tags = [],
        public bool $requiresAuth = false,
        public bool $postOnly = false,
        public bool $hidden = false,
    ) {
    }
}
