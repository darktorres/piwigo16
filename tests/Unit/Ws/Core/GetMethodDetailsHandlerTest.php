<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Core\GetMethodDetailsHandler;
use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsInitializer;
use Piwigo\Ws\WsParamType;

/**
 * Piwigo\Ws\Core\GetMethodDetailsHandler -- `reflection.getMethodDetails`.
 * Same `WsInitializer`-priming setup as GetMethodListHandlerTest.php --
 * see its own docblock.
 */
function pwgCoreGetMethodDetailsHandlerTestServer(): Server
{
    $wsInitializer = Kernel::container()->get(WsInitializer::class);
    if (! $wsInitializer instanceof WsInitializer) {
        throw new LogicException('Container returned an unexpected type for ' . WsInitializer::class);
    }

    return $wsInitializer->init();
}

function pwgCoreGetMethodDetailsHandlerTestSubject(): GetMethodDetailsHandler
{
    $handler = Kernel::container()->get(GetMethodDetailsHandler::class);
    if (! $handler instanceof GetMethodDetailsHandler) {
        throw new LogicException('Container returned an unexpected type for ' . GetMethodDetailsHandler::class);
    }

    return $handler;
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('returns INVALID_PARAM for a non-existent method name', function (): void {
    pwgCoreGetMethodDetailsHandlerTestServer();

    $result = pwgCoreGetMethodDetailsHandlerTestSubject()([
        'methodName' => 'does.not.exist',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(WsError::InvalidParam->value);
    }
});

test('describes a real method\'s full param signature', function (): void {
    $server = pwgCoreGetMethodDetailsHandlerTestServer();
    $server->register(MethodDefinition::forLegacyCallback(
        name: 'test.method',
        callback: fn (array $params): array => [],
        description: 'Does a thing',
        params: [
            ParamDefinition::required('category_id', WsParamType::ID),
            ParamDefinition::optional('name', 'x', info: 'a name'),
        ],
        requiresAuth: true,
    ));

    $result = pwgCoreGetMethodDetailsHandlerTestSubject()([
        'methodName' => 'test.method',
    ]);

    expect($result)
        ->toBeArray();
    if (is_array($result)) {
        expect($result['name'])->toBe('test.method')
            ->and($result['description'])->toBe('Does a thing')
            ->and($result['options'])->toBe([
                'admin_only' => true,
            ]);

        $params = $result['params'];
        $byName = [];
        if (is_array($params)) {
            foreach ($params as $paramData) {
                if (is_array($paramData) && is_string($paramData['name'] ?? null)) {
                    $byName[$paramData['name']] = $paramData;
                }
            }
        }
        expect($byName['category_id']['type'])->toBe('int positive notnull')
            ->and($byName['category_id']['optional'])->toBeFalse()
            ->and($byName['name']['optional'])->toBeTrue()
            ->and($byName['name']['defaultValue'])->toBe('x')
            ->and($byName['name']['info'])->toBe('a name');
    }
});
