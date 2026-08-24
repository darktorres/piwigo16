<?php

declare(strict_types=1);

use openapiphp\openapi\Reader;
use openapiphp\openapi\ReferenceContext;
use openapiphp\openapi\spec\OpenApi;
use openapiphp\openapi\spec\Operation;
use openapiphp\openapi\spec\Response as OpenApiResponse;
use openapiphp\openapi\spec\Responses;
use openapiphp\openapi\spec\Tag;
use Piwigo\Bootstrap\RouteDefinitions;

/**
 * Structural checks against the committed `openapi/openapi.yaml`
 * (multi-file, resolved via RESOLVE_MODE_ALL so every `paths/*.yaml`
 * $ref counts too). Deliberately never calls Schema::validate() /
 * OpenApi::validate() -- php-openapi/openapi 2.1.0 hard-rejects
 * `openapi: 3.2.0` there ("Unsupported openapi version: 3.2.0"), even
 * though the Reader itself parses a 3.2 document correctly. Real
 * spec validity (including the parts this library has no model of)
 * is `bun run lint:openapi`'s job.
 *
 * @return array<string, Operation>
 */
function openApiOperations(): array
{
    /** @var array<string, Operation>|null $operations */
    static $operations = null;
    if ($operations !== null) {
        return $operations;
    }

    $openapi = openApiDocument();

    $operations = [];
    foreach ($openapi->paths->getPaths() as $path => $item) {
        foreach ($item->getOperations() as $method => $operation) {
            $operations[strtoupper($method) . ' ' . $path] = $operation;
        }
    }

    return $operations;
}

function openApiDocument(): OpenApi
{
    /** @var OpenApi|null */
    static $doc = null;
    if ($doc === null) {
        $doc = Reader::readFromYamlFile(
            __DIR__ . '/../../../openapi/openapi.yaml',
            OpenApi::class,
            ReferenceContext::RESOLVE_MODE_ALL
        );
    }

    return $doc;
}

test('operation count matches the live /api/v1 route count from RouteDefinitions', function (): void {
    $liveCount = 0;
    foreach (RouteDefinitions::all()->all() as $route) {
        if (! str_starts_with($route->getPath(), '/api/v1')) {
            continue;
        }
        $liveCount += count($route->getMethods());
    }

    expect(openApiOperations())
        ->toHaveCount($liveCount, 'openapi/ operation count must track RouteDefinitions -- a route added/removed without updating the spec should fail this test.');
});

test('every operation has a non-empty operationId, unique across the document', function (): void {
    $seen = [];
    foreach (openApiOperations() as $key => $operation) {
        expect($operation->operationId)
            ->not->toBeNull("{$key} is missing an operationId.")
            ->not->toBe('', "{$key} has an empty operationId.");

        expect(in_array($operation->operationId, $seen, true))
            ->toBeFalse("operationId '{$operation->operationId}' is reused by more than one operation ({$key}).");
        $seen[] = $operation->operationId;
    }
});

test('every operation has a non-empty summary', function (): void {
    foreach (openApiOperations() as $key => $operation) {
        expect($operation->summary)
            ->not->toBeNull("{$key} is missing a summary.")
            ->not->toBe('', "{$key} has an empty summary.");
    }
});

test('every operation has at least one tag, and every tag it uses is declared at the document root', function (): void {
    $declaredTags = array_map(static fn (Tag $tag): string => $tag->name, openApiDocument()->tags);

    foreach (openApiOperations() as $key => $operation) {
        expect($operation->tags)
            ->not->toBeEmpty("{$key} has no tags.");

        foreach ($operation->tags as $tag) {
            expect(in_array($tag, $declaredTags, true))
                ->toBeTrue("{$key} uses tag '{$tag}', which isn't declared in the document root's own tags list.");
        }
    }
});

// Every operation either inherits the document-level `security:
// [{sessionCookie: []}]` (Operation::$security === null, no
// override) or explicitly opts all the way out with `security: []`
// (an empty, but non-null, array) -- there is no real third state on
// this surface (no operation needs a *different*, non-empty security
// scheme than the shared session-cookie one).
test('every operation\'s security override is either absent (inherits global) or an explicit empty opt-out', function (): void {
    foreach (openApiOperations() as $key => $operation) {
        // Operation::$security is declared non-nullable in the library's
        // own PHPDoc, but its real attributeDefaults() is null -- isset()
        // (routed through the class's own __isset(), which already
        // accounts for that) is the accurate "was this overridden at
        // all" check; a direct `=== null` comparison is the one PHPStan
        // (correctly, given the class's own wrong annotation) flags as
        // unreachable.
        if (! isset($operation->security)) {
            continue;
        }

        expect($operation->security)
            ->toBe([], "{$key} has a non-empty security override -- only null (inherit) or [] (explicit public opt-out) are expected shapes on this surface.");
    }
});

test('every response with a content block gives every media type a real schema', function (): void {
    foreach (openApiOperations() as $key => $operation) {
        if (! $operation->responses instanceof Responses) {
            throw new RuntimeException("{$key} has no responses object at all.");
        }

        foreach ($operation->responses->getResponses() as $status => $response) {
            if (! $response instanceof OpenApiResponse) {
                throw new RuntimeException("{$key}'s {$status} response didn't resolve to a real Response object.");
            }

            foreach ($response->content as $mediaTypeName => $mediaType) {
                expect($mediaType->schema)
                    ->not->toBeNull("{$key}'s {$status} {$mediaTypeName} response has no schema.");
            }
        }
    }
});

test('the shared Problem schema and sessionCookie security scheme are both real, referenced components', function (): void {
    $doc = openApiDocument();

    if ($doc->components === null) {
        throw new RuntimeException('The spec has no components object at all.');
    }

    expect($doc->components->schemas)
        ->toHaveKey('Problem');
    expect($doc->components->securitySchemes)
        ->toHaveKey('sessionCookie');
    expect($doc->servers)
        ->not->toBeEmpty('The spec needs a real servers: block (also required by redocly lint\'s no-empty-servers rule).');
});
