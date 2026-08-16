<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Core;

use Override;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsInitializer;
use Piwigo\Ws\WsParamFlag;
use Piwigo\Ws\WsParamType;

/**
 * `reflection.getMethodDetails` -- returns the full description/param
 * signature for a given WS method. `WsInitializer::init()` is safe to call
 * a second time mid-request (see `GetMethodListHandler`'s own docblock).
 */
final readonly class GetMethodDetailsHandler implements WsAction
{
    public function __construct(
        private WsInitializer $wsInitializer,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array<string, mixed>
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|array
    {
        $input = GetMethodDetailsParams::fromArray($params);
        $server = $this->wsInitializer->init();

        if ($input->methodName === null or ! $server->hasMethod($input->methodName)) {
            return new WsErrorResponse(WsError::InvalidParam->value, 'Requested method does not exist');
        }

        $res = [
            'name' => $input->methodName,
            'description' => $server->getMethodDescription($input->methodName),
            'params' => [],
            'options' => $server->getMethodOptions($input->methodName),
        ];

        foreach ($server->getMethodSignature($input->methodName) as $name => $options) {
            // Server::register() always populates 'flags'/'type' as ints,
            // but the signature travels back out through the
            // loosely-typed Server::$methods property.
            $flags = $options['flags'];
            $flags = is_int($flags) ? $flags : 0;
            $type = $options['type'];
            $type = is_int($type) ? $type : 0;

            $param_data = [
                'name' => $name,
                'optional' => Server::hasFlag($flags, WsParamFlag::OPTIONAL),
                'acceptArray' => Server::hasFlag($flags, WsParamFlag::ACCEPT_ARRAY),
                'type' => 'mixed',
            ];

            if (isset($options['default'])) {
                $param_data['defaultValue'] = $options['default'];
            }
            if (isset($options['maxValue'])) {
                $param_data['maxValue'] = $options['maxValue'];
            }
            if (isset($options['info'])) {
                $param_data['info'] = $options['info'];
            }

            if (Server::hasFlag($type, WsParamType::BOOL)) {
                $param_data['type'] = 'bool';
            } elseif (Server::hasFlag($type, WsParamType::INT)) {
                $param_data['type'] = 'int';
            } elseif (Server::hasFlag($type, WsParamType::FLOAT)) {
                $param_data['type'] = 'float';
            }
            if (Server::hasFlag($type, WsParamType::POSITIVE)) {
                $param_data['type'] .= ' positive';
            }
            if (Server::hasFlag($type, WsParamType::NOTNULL)) {
                $param_data['type'] .= ' notnull';
            }

            $res['params'][] = $param_data;
        }

        return $res;
    }
}
