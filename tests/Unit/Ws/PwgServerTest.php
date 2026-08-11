<?php

declare(strict_types=1);

use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\WsError;
use Piwigo\Core\WsParamFlag;
use Piwigo\Core\WsParamType;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;

/**
 * Piwigo\Ws\PwgServer -- the WS framework's own generic method registry/
 * dispatcher. No dedicated Integration/Browser spec of its own, though
 * WsDefaultMethods.php registers every real WS method through
 * `addMethod()` and every Contract test exercises `invoke()`
 * transitively via a real HTTP round-trip.
 *
 * `run()` itself is NOT covered here: its "unknown response format"
 * branch ends in a real `die(0)` (see the plan's own B4 audit -- one of
 * only 2 confirmed exit()/die() branches in the whole legacy WS API
 * bucket), and its happy path just wires reflection methods + delegates
 * to a real `PwgRequestHandler::handleRequest()`, itself a thin wrapper
 * around `invoke()`/`sendResponse()`, both covered directly below.
 * `sendResponse()` also isn't covered here -- it's a 3-line `header()`/
 * `print_r()`/event-dispatch wrapper around `PwgResponseEncoder::
 * encodeResponse()`, itself already covered per-encoder (PwgJsonEncoder/
 * PwgSerialPhpEncoderTest.php/etc).
 *
 * Kernel::boot() is required file-wide (same reasoning as PwgErrorTest.php):
 * several PwgError codes constructed by invoke() itself (401, 405, and
 * WsError::INVALID_METHOD = 501) fall in the HTTP range, which routes
 * PwgError's own constructor through PresentationAccessor::htmlService(),
 * needing a booted DI container.
 */
beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

function pwgServerTestAccessControl(bool $isAdmin): AccessControl
{
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $currentUser->set(new User(
        id: UserId::from(1),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: $isAdmin ? UserStatus::Admin : UserStatus::Normal,
        enabledHigh: false,
    ));

    return new AccessControl(
        HtmlServiceTestFactory::build(),
        new AccessControlTestFakeRedirectServiceNeverCalled(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );
}

function pwgServerTestServer(bool $isAdmin = true, ?CurrentConfig $currentConfig = null): PwgServer
{
    return new PwgServer(
        new EventDispatcher(),
        pwgServerTestAccessControl($isAdmin),
        new ApiKeyRequestFlag(),
        $currentConfig ?? new CurrentConfig(),
    );
}

// --------------------------------------------------------------- addMethod/hasMethod/getters

test('addMethod with a plain param-name list registers a shorthand signature with default flags/type', function (): void {
    $server = pwgServerTestServer();

    $server->addMethod('test.method', fn (array $params, PwgServer &$service): array => [], ['foo', 'bar'], 'A test method');

    expect($server->hasMethod('test.method'))
        ->toBeTrue()
        ->and($server->getMethodDescription('test.method'))
        ->toBe('A test method')
        ->and($server->getMethodSignature('test.method'))
        ->toBe([
            'foo' => [
                'flags' => 0,
                'type' => 0,
            ],
            'bar' => [
                'flags' => 0,
                'type' => 0,
            ],
        ]);
});

test('addMethod with a detailed param options map preserves flags/type and sets OPTIONAL when a default is present', function (): void {
    $server = pwgServerTestServer();

    $server->addMethod('test.method', fn (array $params, PwgServer &$service): array => [], [
        'id' => [
            'type' => WsParamType::ID,
        ],
        'name' => [
            'default' => 'x',
        ],
    ]);

    $signature = $server->getMethodSignature('test.method');

    expect($signature['id'])->toBe([
        'type' => WsParamType::ID,
        'flags' => 0,
    ])
        ->and($signature['name']['flags'])->toBe(WsParamFlag::OPTIONAL)
        ->and($signature['name']['default'])->toBe('x');
});

test('addMethod treats a null description as an empty string and null params as no params', function (): void {
    $server = pwgServerTestServer();

    $server->addMethod('test.method', fn (array $params, PwgServer &$service): array => [], null, null, [
        'admin_only' => true,
    ]);

    expect($server->getMethodDescription('test.method'))
        ->toBe('')
        ->and($server->getMethodSignature('test.method'))
        ->toBe([])
        ->and($server->getMethodOptions('test.method'))
        ->toBe([
            'admin_only' => true,
        ]);
});

test('hasMethod/getMethodDescription/getMethodSignature/getMethodOptions fall back cleanly for an unregistered method', function (): void {
    $server = pwgServerTestServer();

    expect($server->hasMethod('nope'))
        ->toBeFalse()
        ->and($server->getMethodDescription('nope'))
        ->toBe('')
        ->and($server->getMethodSignature('nope'))
        ->toBe([])
        ->and($server->getMethodOptions('nope'))
        ->toBe([]);
});

// --------------------------------------------------------------- isPost/makeArrayParam/hasFlag

test('isPost reflects whether $_POST is non-empty', function (): void {
    $original = $_POST;
    $_POST = [];
    expect(PwgServer::isPost())->toBeFalse();

    $_POST = [
        'a' => '1',
    ];
    expect(PwgServer::isPost())->toBeTrue();

    $_POST = $original;
});

test('makeArrayParam converts null to an empty array and wraps a scalar, leaving a real array untouched', function (): void {
    $null = null;
    PwgServer::makeArrayParam($null);
    expect($null)
        ->toBe([]);

    $scalar = 'x';
    PwgServer::makeArrayParam($scalar);
    expect($scalar)
        ->toBe(['x']);

    $array = ['a', 'b'];
    PwgServer::makeArrayParam($array);
    expect($array)
        ->toBe(['a', 'b']);
});

test('hasFlag is a plain bitwise AND-equality check', function (): void {
    expect(PwgServer::hasFlag(WsParamFlag::FORCE_ARRAY, WsParamFlag::ACCEPT_ARRAY))->toBeTrue()
        ->and(PwgServer::hasFlag(WsParamFlag::OPTIONAL, WsParamFlag::ACCEPT_ARRAY))->toBeFalse()
        ->and(PwgServer::hasFlag(0, WsParamFlag::OPTIONAL))->toBeFalse();
});

// --------------------------------------------------------------- checkType

test('checkType accepts a valid scalar of each type and coerces it', function (): void {
    $bool = '1';
    expect(PwgServer::checkType($bool, WsParamType::BOOL, 'flag'))->toBeNull();
    expect($bool)
        ->toBeTrue();

    $int = '42';
    expect(PwgServer::checkType($int, WsParamType::INT, 'count'))->toBeNull();
    expect($int)
        ->toBe(42);

    $float = '4.5';
    expect(PwgServer::checkType($float, WsParamType::FLOAT, 'ratio'))->toBeNull();
    expect($float)
        ->toBe(4.5);
});

test('checkType rejects an invalid scalar of each type with a descriptive PwgError', function (): void {
    $bool = 'not-a-bool';
    $error = PwgServer::checkType($bool, WsParamType::BOOL, 'flag');
    expect($error)
        ->toBeInstanceOf(PwgError::class);
    if ($error instanceof PwgError) {
        expect($error->code())
            ->toBe(WsError::INVALID_PARAM)
            ->and($error->message())
            ->toBe('flag must be a boolean');
    }

    $int = 'not-an-int';
    $error = PwgServer::checkType($int, WsParamType::INT, 'count');
    expect($error)
        ->toBeInstanceOf(PwgError::class);
    if ($error instanceof PwgError) {
        expect($error->message())
            ->toBe('count must be an integer');
    }

    $float = 'not-a-float';
    $error = PwgServer::checkType($float, WsParamType::FLOAT, 'ratio');
    expect($error)
        ->toBeInstanceOf(PwgError::class);
    if ($error instanceof PwgError) {
        expect($error->message())
            ->toBe('ratio must be a float');
    }
});

test('checkType enforces POSITIVE/NOTNULL as a minimum range of 1, appending the right message suffix', function (): void {
    $zero = '0';
    $error = PwgServer::checkType($zero, WsParamType::ID, 'category_id');
    expect($error)
        ->toBeInstanceOf(PwgError::class);
    if ($error instanceof PwgError) {
        expect($error->message())
            ->toBe('category_id must be an positive and not null integer');
    }

    $one = '1';
    expect(PwgServer::checkType($one, WsParamType::ID, 'category_id'))->toBeNull();
    expect($one)
        ->toBe(1);
});

test('checkType validates every element when the param is an array', function (): void {
    $ints = ['1', '2', 'not-an-int'];
    $error = PwgServer::checkType($ints, WsParamType::INT, 'ids');
    expect($error)
        ->toBeInstanceOf(PwgError::class);
    if ($error instanceof PwgError) {
        expect($error->message())
            ->toBe('ids must only contain integers');
    }

    $valid = ['1', '2', '3'];
    expect(PwgServer::checkType($valid, WsParamType::INT, 'ids'))->toBeNull();
    expect($valid)
        ->toBe([1, 2, 3]);
});

test('checkType leaves an empty-string scalar param untouched (no type coercion attempted)', function (): void {
    $empty = '';
    expect(PwgServer::checkType($empty, WsParamType::INT, 'count'))->toBeNull();
    expect($empty)
        ->toBe('');
});

// --------------------------------------------------------------- invoke

test('invoke returns INVALID_METHOD for an unregistered method name', function (): void {
    $server = pwgServerTestServer();

    $result = $server->invoke('does.not.exist', []);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(WsError::INVALID_METHOD);
    }
});

test('invoke returns a 405 for a post_only method called without POST data', function (): void {
    $original = $_POST;
    $_POST = [];
    $server = pwgServerTestServer();
    $server->addMethod('test.postOnly', fn (array $params, PwgServer &$service): array => [], null, null, [
        'post_only' => true,
    ]);

    $result = $server->invoke('test.postOnly', []);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(405);
    }
    $_POST = $original;
});

test('invoke returns a 401 for an admin_only method called by a non-admin', function (): void {
    $server = pwgServerTestServer(isAdmin: false);
    $server->addMethod('test.adminOnly', fn (array $params, PwgServer &$service): array => [], null, null, [
        'admin_only' => true,
    ]);

    $result = $server->invoke('test.adminOnly', []);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(401);
    }
});

test('invoke returns a 401 when an active API key request targets a config-forbidden method', function (): void {
    $currentConfig = new CurrentConfig();
    $currentConfig->apiKeyForbiddenMethods = ['test.forbidden'];
    $apiKeyFlag = new ApiKeyRequestFlag();
    $apiKeyFlag->activate();
    $server = new PwgServer(new EventDispatcher(), pwgServerTestAccessControl(true), $apiKeyFlag, $currentConfig);
    $server->addMethod('test.forbidden', fn (array $params, PwgServer &$service): array => [
        'ok' => true,
    ]);

    $result = $server->invoke('test.forbidden', []);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(401);
    }
});

test('invoke returns MISSING_PARAM when a required param is absent, and again when it is present but empty', function (): void {
    $server = pwgServerTestServer();
    $server->addMethod('test.method', fn (array $params, PwgServer &$service): array => [], ['name']);

    $absent = $server->invoke('test.method', []);
    expect($absent)
        ->toBeInstanceOf(PwgError::class);
    if ($absent instanceof PwgError) {
        expect($absent->code())
            ->toBe(WsError::MISSING_PARAM)
            ->and($absent->message())
            ->toBe('Missing parameters: name');
    }

    $empty = $server->invoke('test.method', [
        'name' => '',
    ]);
    expect($empty)
        ->toBeInstanceOf(PwgError::class);
    if ($empty instanceof PwgError) {
        expect($empty->code())
            ->toBe(WsError::MISSING_PARAM);
    }
});

test('invoke applies a registered default value for a missing optional param', function (): void {
    $server = pwgServerTestServer();
    $received = null;
    $server->addMethod('test.method', function (array $params, PwgServer &$service) use (&$received): array {
        $received = $params;
        return [];
    }, [
        'name' => [
            'default' => 'anonymous',
        ],
    ]);

    $server->invoke('test.method', []);

    expect($received)
        ->toBe([
            'name' => 'anonymous',
        ]);
});

test('invoke rejects an array value for a param that does not accept arrays', function (): void {
    $server = pwgServerTestServer();
    $server->addMethod('test.method', fn (array $params, PwgServer &$service): array => [], ['name']);

    $result = $server->invoke('test.method', [
        'name' => ['a', 'b'],
    ]);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(WsError::INVALID_PARAM)
            ->and($result->message())
            ->toBe('name must be scalar');
    }
});

test('invoke force-wraps a scalar into an array when FORCE_ARRAY is set', function (): void {
    $server = pwgServerTestServer();
    $received = null;
    $server->addMethod('test.method', function (array $params, PwgServer &$service) use (&$received): array {
        $received = $params;
        return [];
    }, [
        'ids' => [
            'flags' => WsParamFlag::FORCE_ARRAY,
        ],
    ]);

    $server->invoke('test.method', [
        'ids' => '7',
    ]);

    expect($received)
        ->toBe([
            'ids' => ['7'],
        ]);
});

test('invoke rejects a param that fails its declared type check', function (): void {
    $server = pwgServerTestServer();
    $server->addMethod('test.method', fn (array $params, PwgServer &$service): array => [], [
        'category_id' => [
            'type' => WsParamType::ID,
        ],
    ]);

    $result = $server->invoke('test.method', [
        'category_id' => 'not-an-id',
    ]);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(WsError::INVALID_PARAM);
    }
});

test('invoke clamps a param above maxValue down to maxValue', function (): void {
    $server = pwgServerTestServer();
    $received = null;
    $server->addMethod('test.method', function (array $params, PwgServer &$service) use (&$received): array {
        $received = $params;
        return [];
    }, [
        'per_page' => [
            'type' => WsParamType::INT,
            'maxValue' => 100,
        ],
    ]);

    $server->invoke('test.method', [
        'per_page' => '500',
    ]);

    expect($received)
        ->toBe([
            'per_page' => 100,
        ]);
});

test('invoke calls the real registered callback with the checked params and a reference to the service itself', function (): void {
    $server = pwgServerTestServer();
    $server->addMethod('test.method', function (array $params, PwgServer &$service): array {
        return [
            'echo' => $params,
            'sameService' => $service->hasMethod('test.method'),
        ];
    }, ['name']);

    $result = $server->invoke('test.method', [
        'name' => 'Alps',
    ]);

    expect($result)
        ->toBe([
            'echo' => [
                'name' => 'Alps',
            ],
            'sameService' => true,
        ]);
});

// --------------------------------------------------------------- ws_getMethodList / ws_getMethodDetails

test('ws_getMethodList lists only non-hidden methods', function (): void {
    $server = pwgServerTestServer();
    $server->addMethod('test.visible', fn (array $params, PwgServer &$service): array => []);
    $server->addMethod('test.hidden', fn (array $params, PwgServer &$service): array => [], null, null, [
        'hidden' => true,
    ]);

    $result = PwgServer::ws_getMethodList([], $server);

    expect($result['methods']->_content)->toBe(['test.visible']);
});

test('ws_getMethodDetails returns INVALID_PARAM for a non-existent method name', function (): void {
    $server = pwgServerTestServer();

    $result = PwgServer::ws_getMethodDetails([
        'methodName' => 'does.not.exist',
    ], $server);

    expect($result)
        ->toBeInstanceOf(PwgError::class);
    if ($result instanceof PwgError) {
        expect($result->code())
            ->toBe(WsError::INVALID_PARAM);
    }
});

test('ws_getMethodDetails describes a real method\'s full param signature', function (): void {
    $server = pwgServerTestServer();
    $server->addMethod('test.method', fn (array $params, PwgServer &$service): array => [], [
        'category_id' => [
            'type' => WsParamType::ID,
        ],
        'name' => [
            'default' => 'x',
            'info' => 'a name',
        ],
    ], 'Does a thing', [
        'admin_only' => true,
    ]);

    $result = PwgServer::ws_getMethodDetails([
        'methodName' => 'test.method',
    ], $server);

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

// --------------------------------------------------------------- isAuthorizedMethodForAPIKEY

test('isAuthorizedMethodForAPIKEY allows any method when no API key is active', function (): void {
    $server = pwgServerTestServer();

    expect($server->isAuthorizedMethodForAPIKEY('pwg.users.setInfo'))
        ->toBeTrue();
});

test('isAuthorizedMethodForAPIKEY blocks a config-forbidden method once the API key flag is active', function (): void {
    $currentConfig = new CurrentConfig();
    $currentConfig->apiKeyForbiddenMethods = ['pwg.users.setInfo'];
    $apiKeyFlag = new ApiKeyRequestFlag();
    $apiKeyFlag->activate();
    $server = new PwgServer(new EventDispatcher(), pwgServerTestAccessControl(true), $apiKeyFlag, $currentConfig);

    expect($server->isAuthorizedMethodForAPIKEY('pwg.users.setInfo'))
        ->toBeFalse()
        ->and($server->isAuthorizedMethodForAPIKEY('pwg.categories.getList'))
        ->toBeTrue();
});

test('isAuthorizedMethodForAPIKEY also blocks via a ws_session_login_api_key session marker, without the request-scoped flag', function (): void {
    $originalSession = $_SESSION ?? [];
    $_SESSION['connected_with'] = 'ws_session_login_api_key';
    $currentConfig = new CurrentConfig();
    $currentConfig->apiKeyForbiddenMethods = ['pwg.users.setInfo'];
    $server = new PwgServer(new EventDispatcher(), pwgServerTestAccessControl(true), new ApiKeyRequestFlag(), $currentConfig);

    try {
        expect($server->isAuthorizedMethodForAPIKEY('pwg.users.setInfo'))
            ->toBeFalse();
    } finally {
        $_SESSION = $originalSession;
    }
});
