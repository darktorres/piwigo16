<?php

declare(strict_types=1);

namespace Piwigo\Tools\OpenApi;

/**
 * One route's collected draft facts -- `tools/openapi-bootstrap.php`'s own
 * working structure, pulled out to a typed class instead of a loose
 * associative array so PHPStan can actually verify the pipeline that
 * builds and later serializes it, the same "typed all the way through"
 * reasoning every real `*Input`/`*Response` shape in `src/` already
 * follows.
 */
final readonly class OperationDraft
{
    /**
     * @param list<string> $methods
     * @param array<string, string> $requirements
     * @param list<string> $guards
     * @param array<string, array{type: string, nullable: bool}>|null $requestSchema
     *   null means no typed *Input DTO was found for this controller at
     *   all -- Phase 2 authors the request body from scratch.
     * @param list<array{line: int, keys: ?list<string>}> $responseCalls
     *   a null `keys` entry means the response array literal couldn't be
     *   resolved (see ResponseBodyCallSiteVisitor's own docblock) --
     *   Phase 2 authors that one response from scratch.
     */
    public function __construct(
        public string $routeName,
        public string $path,
        public array $methods,
        public array $requirements,
        public string $controllerClass,
        public array $guards,
        public ?array $requestSchema,
        public array $responseCalls,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toDraftArray(): array
    {
        $requestSchemaYaml = $this->requestSchema === null
            ? 'TODO: no typed Input DTO found -- author this request body schema by hand'
            : array_map(
                static fn (array $f): array => [
                    'type' => $f['type'],
                    'nullable' => $f['nullable'],
                ],
                $this->requestSchema
            );

        $responseCallsYaml = array_map(static function (array $call): array {
            if ($call['keys'] === null) {
                return [
                    'line' => $call['line'],
                    'fields' => 'TODO: unresolved by static analysis -- author this response schema by hand, reading the real controller/presenter logic',
                ];
            }

            return [
                'line' => $call['line'],
                'fields' => array_fill_keys($call['keys'], 'TODO: type'),
            ];
        }, $this->responseCalls);

        return [
            'routeName' => $this->routeName,
            'path' => $this->path,
            'methods' => $this->methods,
            'requirements' => $this->requirements,
            'controllerClass' => $this->controllerClass,
            'guards' => $this->guards,
            'requestSchema' => $requestSchemaYaml,
            'responseCalls' => $responseCallsYaml,
        ];
    }
}
