<?php

declare(strict_types=1);

namespace Piwigo\Ws\OpenApi;

use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsParam;
use Piwigo\Ws\WsType;

/**
 * Builds an OpenAPI 3.1 document from methods registered with PwgServer.
 *
 * The spec is generated at request time from live registration metadata —
 * no hand-maintained YAML. Each WS method becomes a virtual path under /ws/.
 * POST-only methods are documented as HTTP POST with form-encoded body;
 * all others as HTTP GET with query parameters.
 *
 * @phpstan-import-type WsMethod   from PwgServer
 * @phpstan-import-type WsParamDef from PwgServer
 */
final class SpecBuilder
{
    public function __construct(
        private readonly PwgServer $server,
        private readonly string $serverUrl = '',
    ) {
    }

    public function build(): OpenApiDocument
    {
        $version = defined('PHPWG_VERSION') ? (string) constant('PHPWG_VERSION') : '16.x';
        $defs    = $this->server->getMethodDefs();

        $paths = [];
        foreach ($this->server->getMethods() as $name => $method) {
            if (!empty($method['options']['hidden'])) {
                continue;
            }
            $def    = $defs[$name] ?? null;
            $paths['/ws/' . $name] = $this->buildPathItem($name, $method, $def);
        }

        $spec = [
            'openapi' => '3.1.0',
            'info'    => [
                'title'       => 'Piwigo Web Services',
                'version'     => $version,
                'description' => 'Auto-generated from PwgServer registered methods. '
                    . 'Access via `ws.php?method=<methodName>` (GET) or POST body.',
                'contact'     => ['url' => 'https://piwigo.org'],
                'license'     => ['name' => 'GPL-2.0-or-later'],
            ],
            'components' => [
                'securitySchemes' => [
                    'cookieAuth' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'pwg_id'],
                    'apiKeyAuth' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'Authorization'],
                ],
            ],
            'paths' => $paths,
        ];

        if ($this->serverUrl !== '') {
            $spec['servers'] = [['url' => $this->serverUrl]];
        }

        return new OpenApiDocument($spec);
    }

    /**
     * @phpstan-param WsMethod $method
     * @return array<mixed>
     */
    private function buildPathItem(string $name, array $method, ?MethodDefinition $def): array
    {
        $isPost    = $def !== null ? $def->postOnly
            : (isset($method['options']['post_only']) && (bool) $method['options']['post_only']);
        $httpVerb  = $isPost ? 'post' : 'get';
        $adminOnly = $def !== null ? $def->requiresAuth
            : (isset($method['options']['admin_only']) && (bool) $method['options']['admin_only']);

        $rawDesc   = $method['description'];
        $tags      = ($def !== null && $def->tags !== []) ? $def->tags : $this->inferTags($name);
        $operation = [
            'operationId' => str_replace('.', '_', $name),
            'summary'     => $rawDesc !== '' ? $this->firstLine($rawDesc) : $name,
            'tags'        => $tags,
        ];

        if ($rawDesc !== '') {
            $operation['description'] = $rawDesc;
        }

        if ($adminOnly) {
            $operation['security']      = [['cookieAuth' => []], ['apiKeyAuth' => []]];
            $operation['x-admin-only']  = true;
        }

        $parameters     = [];
        $formProperties = [];
        $formRequired   = [];

        foreach ($method['signature'] as $paramName => $paramDef) {
            $isOptional = self::hasFlag($paramDef['flags'], WsParam::Optional->value);
            $schema     = $this->buildParamSchema($paramDef);

            if (array_key_exists('default', $paramDef)) {
                $default = $paramDef['default'];
                if (is_scalar($default)) {
                    $schema['default'] = $default;
                }
            }

            $info = isset($paramDef['info']) ? $paramDef['info'] : '';

            if ($isPost) {
                if ($info !== '') {
                    $schema['description'] = $info;
                }
                $formProperties[$paramName] = $schema;
                if (!$isOptional) {
                    $formRequired[] = $paramName;
                }
            } else {
                $param = [
                    'name'     => $paramName,
                    'in'       => 'query',
                    'required' => !$isOptional,
                    'schema'   => $schema,
                ];
                if ($info !== '') {
                    $param['description'] = $info;
                }
                $parameters[] = $param;
            }
        }

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($formProperties !== []) {
            $bodySchema = ['type' => 'object', 'properties' => $formProperties];
            if ($formRequired !== []) {
                $bodySchema['required'] = $formRequired;
            }
            $operation['requestBody'] = [
                'required' => true,
                'content'  => [
                    'application/x-www-form-urlencoded' => ['schema' => $bodySchema],
                ],
            ];
        }

        $operation['responses'] = [
            '200' => [
                'description' => 'Successful response',
                'content'     => ['application/json' => ['schema' => ['type' => 'object']]],
            ],
            '400' => ['description' => 'Invalid parameters'],
            '401' => ['description' => 'Authentication required'],
        ];

        return [$httpVerb => $operation];
    }

    /**
     * @phpstan-param WsParamDef $paramDef
     * @return array<mixed>
     */
    private function buildParamSchema(array $paramDef): array
    {
        $flags  = $paramDef['flags'];
        $scalar = $this->buildScalarSchema($paramDef['type']);

        if (isset($paramDef['maxValue'])) {
            $scalar['maximum'] = $paramDef['maxValue'];
        }

        if (self::hasFlag($flags, WsParam::ForceArray->value)) {
            return ['type' => 'array', 'items' => $scalar];
        }

        if (self::hasFlag($flags, WsParam::AcceptArray->value)) {
            return ['oneOf' => [$scalar, ['type' => 'array', 'items' => $scalar]]];
        }

        return $scalar;
    }

    /** @return array<mixed> */
    private function buildScalarSchema(int $type): array
    {
        if (($type & WsType::Bool->value) !== 0) {
            return ['type' => 'boolean'];
        }

        if (($type & WsType::Int->value) !== 0) {
            $schema = ['type' => 'integer', 'format' => 'int32'];
            if (($type & WsType::NotNull->value) !== 0) {
                $schema['minimum'] = 1;
            } elseif (($type & WsType::Positive->value) !== 0) {
                $schema['minimum'] = 0;
            }
            return $schema;
        }

        if (($type & WsType::Float->value) !== 0) {
            $schema = ['type' => 'number', 'format' => 'float'];
            if (($type & WsType::Positive->value) !== 0) {
                $schema['minimum'] = 0;
            }
            return $schema;
        }

        return ['type' => 'string'];
    }

    /** @return string[] */
    private function inferTags(string $methodName): array
    {
        $parts = explode('.', $methodName);
        return match (count($parts)) {
            1       => ['general'],
            2       => [$parts[0]],   // e.g. 'pwg.getVersion' → ['pwg']
            default => [$parts[1]],   // e.g. 'pwg.images.getInfo' → ['images']
        };
    }

    private function firstLine(string $text): string
    {
        $line = (string) strtok(trim(strip_tags($text)), "\n");
        return strlen($line) > 120 ? substr($line, 0, 117) . '...' : $line;
    }

    private static function hasFlag(int $value, int $flag): bool
    {
        return ($value & $flag) === $flag;
    }
}
