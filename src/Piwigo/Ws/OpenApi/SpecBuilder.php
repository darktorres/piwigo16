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
final readonly class SpecBuilder
{
    public function __construct(
        private PwgServer $server,
        private string $serverUrl = '',
    ) {
    }

    public function build(): OpenApiDocument
    {
        $versionRaw = defined('PHPWG_VERSION') ? constant('PHPWG_VERSION') : '16.x';
        $version = is_scalar($versionRaw) ? (string) $versionRaw : '16.x';
        $defs    = $this->server->getMethodDefs();

        $paths = [];
        foreach ($this->server->getMethods() as $name => $method) {
            if (isset($method['options']['hidden']) && $method['options']['hidden'] !== '' && $method['options']['hidden'] !== false && $method['options']['hidden'] !== 0) {
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
                    . 'Access via the `/ws` route with `?method=<methodName>` (GET) or POST body.',
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

        $apiAttr = $def !== null ? $this->extractApiMethodAttribute($def->callback) : null;
        $rawDesc = $method['description'];

        // #[ApiMethod] on the endpoint method is authoritative for plugin authors;
        // fall back to MethodDefinition tags, then inferred tags from the name.
        $tags = ($apiAttr !== null && $apiAttr->tags !== [])
            ? $apiAttr->tags
            : (($def !== null && $def->tags !== []) ? $def->tags : $this->inferTags($name));

        $summary = ($apiAttr !== null && $apiAttr->summary !== '')
            ? $apiAttr->summary
            : ($rawDesc !== '' ? $this->firstLine($rawDesc) : $name);

        $operation = [
            'operationId' => str_replace('.', '_', $name),
            'summary'     => $summary,
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

            $info = $paramDef['info'] ?? '';

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

    /**
     * Reflects on a MethodDefinition callback to find an #[ApiMethod]
     * attribute on the target method. Supports first-class callable syntax
     * (`$obj->method(...)`, `Class::staticMethod(...)`) and `[obj, 'm']`
     * arrays. Returns null for anonymous closures, function-name strings,
     * and any callback whose target cannot be resolved.
     */
    private function extractApiMethodAttribute(mixed $callback): ?ApiMethod
    {
        $rm = null;

        if ($callback instanceof \Closure) {
            $rf = new \ReflectionFunction($callback);
            if ($rf->isAnonymous()) {
                return null;
            }
            $bound    = $rf->getClosureThis();
            $scopeRef = $rf->getClosureScopeClass();

            if ($bound !== null) {
                $rm = new \ReflectionMethod($bound, $rf->getName());
            } elseif ($scopeRef !== null) {
                $rm = new \ReflectionMethod($scopeRef->getName(), $rf->getName());
            }
        } elseif (is_array($callback) && count($callback) === 2) {
            [$target, $methodName] = $callback;
            if (is_string($methodName) && is_object($target)) {
                $rm = new \ReflectionMethod($target, $methodName);
            } elseif (is_string($methodName) && is_string($target) && class_exists($target)) {
                $rm = new \ReflectionMethod($target, $methodName);
            }
        }

        if ($rm === null) {
            return null;
        }

        $attrs = $rm->getAttributes(ApiMethod::class);
        return $attrs === [] ? null : $attrs[0]->newInstance();
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
