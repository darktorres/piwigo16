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
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Tests\Unit\Ws\ServerTestFakeWsAction;
use Piwigo\Tests\Unit\Ws\ServerTestFakeWsActionReturnsResult;
use Piwigo\Tests\Unit\Ws\ServerTestFakeWsActionThrows;
use Piwigo\Tests\Unit\Ws\ServerTestFakeWsActionThrowsUnsupportedMediaType;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\Protocol\JsonEncoder;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsParamFlag;
use Piwigo\Ws\WsParamType;

/**
 * Piwigo\Ws\Server -- the WS framework's own generic method registry/
 * dispatcher. No dedicated Integration/Browser spec of its own, though
 * WsDefaultMethods.php registers every real WS method through
 * `addMethod()` and every Contract test exercises `invoke()`
 * transitively via a real HTTP round-trip.
 *
 * `run()` itself is NOT covered here: it just wires reflection methods
 * and delegates to a real `RequestHandler::handleRequest()`, itself a
 * thin wrapper around `invoke()`/`sendResponse()`, both covered directly
 * below (P25 Stage 2 items 1-2 deleted its former "unknown response
 * format" `die(0)` branch and the internal-state `var_export()` it
 * dumped to the client -- see WsServerTest.php's own Contract-level
 * coverage for that branch now). `sendResponse()`'s own encoding is
 * NOT re-covered here either -- it's a thin wrapper around
 * `ResponseEncoder::encodeResponse()`, itself already covered
 * per-encoder (JsonEncoder/SerialPhpEncoderTest.php/etc) -- but its own
 * WsErrorResponse-code-to-HTTP-status mapping (P25 Stage 2 item 3, moved
 * out of WsErrorResponse's own constructor) gets its own boundary
 * coverage in the `sendResponse` section below.
 *
 * Kernel::boot() is required file-wide: constructing a real Server needs
 * AccessControl's own dependency chain (-> RedirectService -> Lang),
 * which needs a booted DI container regardless of anything WsErrorResponse
 * itself does.
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

function pwgServerTestServer(bool $isAdmin = true, ?CurrentConfig $currentConfig = null): Server
{
    return new Server(
        new EventDispatcher(),
        pwgServerTestAccessControl($isAdmin),
        new ApiKeyRequestFlag(),
        $currentConfig ?? new CurrentConfig(),
        Kernel::container(),
    );
}

// --------------------------------------------------------------- addMethod/hasMethod/getters

test('addMethod with a plain param-name list registers a shorthand signature with default flags/type', function (): void {
    $server = pwgServerTestServer();

    $server->addMethod('test.method', fn (array $params, Server &$service): array => [], ['foo', 'bar'], 'A test method');

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

    $server->addMethod('test.method', fn (array $params, Server &$service): array => [], [
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

    $server->addMethod('test.method', fn (array $params, Server &$service): array => [], null, null, [
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
    expect(Server::isPost())->toBeFalse();

    $_POST = [
        'a' => '1',
    ];
    expect(Server::isPost())->toBeTrue();

    $_POST = $original;
});

test('makeArrayParam converts null to an empty array and wraps a scalar, leaving a real array untouched', function (): void {
    $null = null;
    Server::makeArrayParam($null);
    expect($null)
        ->toBe([]);

    $scalar = 'x';
    Server::makeArrayParam($scalar);
    expect($scalar)
        ->toBe(['x']);

    $array = ['a', 'b'];
    Server::makeArrayParam($array);
    expect($array)
        ->toBe(['a', 'b']);
});

test('hasFlag is a plain bitwise AND-equality check', function (): void {
    expect(Server::hasFlag(WsParamFlag::FORCE_ARRAY, WsParamFlag::ACCEPT_ARRAY))->toBeTrue()
        ->and(Server::hasFlag(WsParamFlag::OPTIONAL, WsParamFlag::ACCEPT_ARRAY))->toBeFalse()
        ->and(Server::hasFlag(0, WsParamFlag::OPTIONAL))->toBeFalse();
});

// --------------------------------------------------------------- checkType

test('checkType accepts a valid scalar of each type and coerces it', function (): void {
    $bool = '1';
    expect(Server::checkType($bool, WsParamType::BOOL, 'flag'))->toBeNull();
    expect($bool)
        ->toBeTrue();

    $int = '42';
    expect(Server::checkType($int, WsParamType::INT, 'count'))->toBeNull();
    expect($int)
        ->toBe(42);

    $float = '4.5';
    expect(Server::checkType($float, WsParamType::FLOAT, 'ratio'))->toBeNull();
    expect($float)
        ->toBe(4.5);
});

test('checkType rejects an invalid scalar of each type with a descriptive WsErrorResponse', function (): void {
    $bool = 'not-a-bool';
    $error = Server::checkType($bool, WsParamType::BOOL, 'flag');
    expect($error)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($error instanceof WsErrorResponse) {
        expect($error->code())
            ->toBe(WsError::InvalidParam->value)
            ->and($error->message())
            ->toBe('flag must be a boolean');
    }

    $int = 'not-an-int';
    $error = Server::checkType($int, WsParamType::INT, 'count');
    expect($error)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($error instanceof WsErrorResponse) {
        expect($error->message())
            ->toBe('count must be an integer');
    }

    $float = 'not-a-float';
    $error = Server::checkType($float, WsParamType::FLOAT, 'ratio');
    expect($error)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($error instanceof WsErrorResponse) {
        expect($error->message())
            ->toBe('ratio must be a float');
    }
});

test('checkType enforces POSITIVE/NOTNULL as a minimum range of 1, appending the right message suffix', function (): void {
    $zero = '0';
    $error = Server::checkType($zero, WsParamType::ID, 'category_id');
    expect($error)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($error instanceof WsErrorResponse) {
        expect($error->message())
            ->toBe('category_id must be an positive and not null integer');
    }

    $one = '1';
    expect(Server::checkType($one, WsParamType::ID, 'category_id'))->toBeNull();
    expect($one)
        ->toBe(1);
});

test('checkType validates every element when the param is an array', function (): void {
    $ints = ['1', '2', 'not-an-int'];
    $error = Server::checkType($ints, WsParamType::INT, 'ids');
    expect($error)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($error instanceof WsErrorResponse) {
        expect($error->message())
            ->toBe('ids must only contain integers');
    }

    $valid = ['1', '2', '3'];
    expect(Server::checkType($valid, WsParamType::INT, 'ids'))->toBeNull();
    expect($valid)
        ->toBe([1, 2, 3]);
});

test('checkType leaves an empty-string scalar param untouched (no type coercion attempted)', function (): void {
    $empty = '';
    expect(Server::checkType($empty, WsParamType::INT, 'count'))->toBeNull();
    expect($empty)
        ->toBe('');
});

// --------------------------------------------------------------- invoke

test('invoke returns INVALID_METHOD for an unregistered method name', function (): void {
    $server = pwgServerTestServer();

    $result = $server->invoke('does.not.exist', []);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(WsError::InvalidMethod->value);
    }
});

test('invoke returns a 405 for a post_only method called without POST data', function (): void {
    $original = $_POST;
    $_POST = [];
    $server = pwgServerTestServer();
    $server->addMethod('test.postOnly', fn (array $params, Server &$service): array => [], null, null, [
        'post_only' => true,
    ]);

    $result = $server->invoke('test.postOnly', []);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(405);
    }
    $_POST = $original;
});

test('invoke returns a 401 for an admin_only method called by a non-admin', function (): void {
    $server = pwgServerTestServer(isAdmin: false);
    $server->addMethod('test.adminOnly', fn (array $params, Server &$service): array => [], null, null, [
        'admin_only' => true,
    ]);

    $result = $server->invoke('test.adminOnly', []);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(401);
    }
});

test('invoke returns a 401 when an active API key request targets a config-forbidden method', function (): void {
    $currentConfig = new CurrentConfig();
    $currentConfig->apiKeyForbiddenMethods = ['test.forbidden'];
    $apiKeyFlag = new ApiKeyRequestFlag();
    $apiKeyFlag->activate();
    $server = new Server(new EventDispatcher(), pwgServerTestAccessControl(true), $apiKeyFlag, $currentConfig, Kernel::container());
    $server->addMethod('test.forbidden', fn (array $params, Server &$service): array => [
        'ok' => true,
    ]);

    $result = $server->invoke('test.forbidden', []);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(401);
    }
});

test('invoke returns MISSING_PARAM when a required param is absent, and again when it is present but empty', function (): void {
    $server = pwgServerTestServer();
    $server->addMethod('test.method', fn (array $params, Server &$service): array => [], ['name']);

    $absent = $server->invoke('test.method', []);
    expect($absent)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($absent instanceof WsErrorResponse) {
        expect($absent->code())
            ->toBe(WsError::MissingParam->value)
            ->and($absent->message())
            ->toBe('Missing parameters: name');
    }

    $empty = $server->invoke('test.method', [
        'name' => '',
    ]);
    expect($empty)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($empty instanceof WsErrorResponse) {
        expect($empty->code())
            ->toBe(WsError::MissingParam->value);
    }
});

test('invoke applies a registered default value for a missing optional param', function (): void {
    $server = pwgServerTestServer();
    $received = null;
    $server->addMethod('test.method', function (array $params, Server &$service) use (&$received): array {
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
    $server->addMethod('test.method', fn (array $params, Server &$service): array => [], ['name']);

    $result = $server->invoke('test.method', [
        'name' => ['a', 'b'],
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(WsError::InvalidParam->value)
            ->and($result->message())
            ->toBe('name must be scalar');
    }
});

test('invoke force-wraps a scalar into an array when FORCE_ARRAY is set', function (): void {
    $server = pwgServerTestServer();
    $received = null;
    $server->addMethod('test.method', function (array $params, Server &$service) use (&$received): array {
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
    $server->addMethod('test.method', fn (array $params, Server &$service): array => [], [
        'category_id' => [
            'type' => WsParamType::ID,
        ],
    ]);

    $result = $server->invoke('test.method', [
        'category_id' => 'not-an-id',
    ]);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(WsError::InvalidParam->value);
    }
});

test('invoke clamps a param above maxValue down to maxValue', function (): void {
    $server = pwgServerTestServer();
    $received = null;
    $server->addMethod('test.method', function (array $params, Server &$service) use (&$received): array {
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
    $server->addMethod('test.method', fn (array $params, Server &$service): array => [
        'echo' => $params,
        'sameService' => $service->hasMethod('test.method'),
    ], ['name']);

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

// --------------------------------------------------------------- register/handlerClass dispatch (Group 19)

test('register stores a handlerClass-based method with a normalized signature/options', function (): void {
    $server = pwgServerTestServer();

    $server->register(new MethodDefinition(
        name: 'test.handlerClass',
        handlerClass: ServerTestFakeWsAction::class,
        description: 'A handler-based test method',
        params: [
            ParamDefinition::required('category_id', WsParamType::ID),
            ParamDefinition::optional('name', 'x', info: 'a name'),
        ],
        requiresAuth: true,
    ));

    expect($server->hasMethod('test.handlerClass'))
        ->toBeTrue()
        ->and($server->getMethodDescription('test.handlerClass'))
        ->toBe('A handler-based test method')
        ->and($server->getMethodOptions('test.handlerClass'))
        ->toBe([
            'admin_only' => true,
        ])
        ->and($server->getMethodSignature('test.handlerClass'))
        ->toBe([
            'category_id' => [
                'flags' => 0,
                'type' => WsParamType::ID,
            ],
            'name' => [
                'flags' => WsParamFlag::OPTIONAL,
                'type' => 0,
                'default' => 'x',
                'info' => 'a name',
            ],
        ]);
});

test('invoke resolves a handlerClass-registered method from the container and calls it', function (): void {
    $server = pwgServerTestServer();
    $server->register(new MethodDefinition(
        name: 'test.handlerClass',
        handlerClass: ServerTestFakeWsAction::class,
        params: [
            ParamDefinition::required('name'),
        ],
    ));

    $result = $server->invoke('test.handlerClass', [
        'name' => 'Alps',
    ]);

    expect($result)
        ->toBe([
            'echo' => [
                'name' => 'Alps',
            ],
        ]);
});

test('invoke converts a WsParamException thrown by a handlerClass into a 403 WsErrorResponse', function (): void {
    $server = pwgServerTestServer();
    $server->register(new MethodDefinition(
        name: 'test.handlerClass',
        handlerClass: ServerTestFakeWsActionThrows::class,
    ));

    $result = $server->invoke('test.handlerClass', []);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403)
            ->and($result->message())
            ->toBe('Bad params');
    }
});

test('invoke converts an UnsupportedMediaTypeException thrown by a handlerClass into a 415 WsErrorResponse', function (): void {
    $server = pwgServerTestServer();
    $server->register(new MethodDefinition(
        name: 'test.handlerClass',
        handlerClass: ServerTestFakeWsActionThrowsUnsupportedMediaType::class,
    ));

    $result = $server->invoke('test.handlerClass', []);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(415)
            ->and($result->message())
            ->toBe('Wrong file type');
    }
});

test('invoke unwraps a WsResult returned by a handlerClass via toArray()', function (): void {
    $server = pwgServerTestServer();
    $server->register(new MethodDefinition(
        name: 'test.handlerClass',
        handlerClass: ServerTestFakeWsActionReturnsResult::class,
    ));

    $result = $server->invoke('test.handlerClass', []);

    expect($result)
        ->toBe([
            'wrapped' => true,
        ]);
});

// --------------------------------------------------------------- wsGetMethodList / wsGetMethodDetails

test('wsGetMethodList lists only non-hidden methods', function (): void {
    $server = pwgServerTestServer();
    $server->addMethod('test.visible', fn (array $params, Server &$service): array => []);
    $server->addMethod('test.hidden', fn (array $params, Server &$service): array => [], null, null, [
        'hidden' => true,
    ]);

    $result = Server::wsGetMethodList([], $server);

    expect($result['methods']->content)->toBe(['test.visible']);
});

test('wsGetMethodDetails returns INVALID_PARAM for a non-existent method name', function (): void {
    $server = pwgServerTestServer();

    $result = Server::wsGetMethodDetails([
        'methodName' => 'does.not.exist',
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(WsError::InvalidParam->value);
    }
});

test('wsGetMethodDetails describes a real method\'s full param signature', function (): void {
    $server = pwgServerTestServer();
    $server->addMethod('test.method', fn (array $params, Server &$service): array => [], [
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

    $result = Server::wsGetMethodDetails([
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
    $server = new Server(new EventDispatcher(), pwgServerTestAccessControl(true), $apiKeyFlag, $currentConfig, Kernel::container());

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
    $server = new Server(new EventDispatcher(), pwgServerTestAccessControl(true), new ApiKeyRequestFlag(), $currentConfig, Kernel::container());

    try {
        expect($server->isAuthorizedMethodForAPIKEY('pwg.users.setInfo'))
            ->toBeFalse();
    } finally {
        $_SESSION = $originalSession;
    }
});

// --------------------------------------------------------------- sendResponse

test('sendResponse maps a WsErrorResponse code below the HTTP range onto a 200 status', function (): void {
    $server = pwgServerTestServer();
    $server->setEncoder('json', new JsonEncoder());

    $response = $server->sendResponse(new WsErrorResponse(1003, 'Invalid param foo'));

    expect($response->getStatusCode())
        ->toBe(200);
});

test('sendResponse maps a WsErrorResponse code at the lower HTTP boundary (400) onto that status', function (): void {
    $server = pwgServerTestServer();
    $server->setEncoder('json', new JsonEncoder());

    $response = $server->sendResponse(new WsErrorResponse(400, 'Bad request'));

    expect($response->getStatusCode())
        ->toBe(400);
});

test('sendResponse maps a WsErrorResponse code at the upper HTTP boundary (599) onto that status', function (): void {
    // Kills a GreaterOrEqualToSmaller-style mutant on the `< 600` check:
    // for 599, `< 600` is true but `<= 599` alone wouldn't distinguish a
    // mutant flipping the boundary the other way.
    $server = pwgServerTestServer();
    $server->setEncoder('json', new JsonEncoder());

    $response = $server->sendResponse(new WsErrorResponse(599, 'Custom 599'));

    expect($response->getStatusCode())
        ->toBe(599);
});

test('sendResponse maps a WsErrorResponse code just past the HTTP range (600) onto a 200 status', function (): void {
    $server = pwgServerTestServer();
    $server->setEncoder('json', new JsonEncoder());

    $response = $server->sendResponse(new WsErrorResponse(600, 'Not an HTTP code either'));

    expect($response->getStatusCode())
        ->toBe(200);
});

test('sendResponse maps a non-error response onto a 200 status', function (): void {
    $server = pwgServerTestServer();
    $server->setEncoder('json', new JsonEncoder());

    $response = $server->sendResponse([
        'ok' => true,
    ]);

    expect($response->getStatusCode())
        ->toBe(200);
});
