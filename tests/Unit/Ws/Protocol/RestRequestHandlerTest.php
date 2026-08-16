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
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\Protocol\JsonEncoder;
use Piwigo\Ws\Protocol\RestRequestHandler;
use Piwigo\Ws\Server;

/**
 * Piwigo\Ws\Protocol\RestRequestHandler -- the sole real
 * `RequestHandler` implementation wired by `WsInitializer::init()`.
 * No dedicated Integration/Browser spec of its own -- every real WS
 * HTTP request reaches it transitively through `WsController`.
 *
 * `handleRequest()`'s own `$_GET`/`$_POST` reads go through
 * `WsRawRequest::fromGlobals()`, already covered directly by
 * `WsRawRequestTest.php` for the raw method-name/param-bag extraction
 * logic -- this file only covers the 2 real branches specific to
 * `handleRequest()` itself: no method name resolved at all, and a real
 * method dispatched through to `Server::sendResponse()`.
 *
 * `handleRequest()` returns the real ResponseInterface `sendResponse()`
 * builds (P25 Stage 2 items 1-2), read directly here via
 * `getBody()`/`getStatusCode()` -- no output buffering needed anymore.
 */
function pwgRestRequestHandlerTestAccessControl(): AccessControl
{
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $currentUser->set(new User(
        id: UserId::from(1),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Admin,
        enabledHigh: false,
    ));

    return new AccessControl(
        HtmlServiceTestFactory::build(),
        new AccessControlTestFakeRedirectServiceNeverCalled(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );
}

function pwgRestRequestHandlerTestServer(): Server
{
    $server = new Server(
        new EventDispatcher(),
        pwgRestRequestHandlerTestAccessControl(),
        new ApiKeyRequestFlag(),
        new CurrentConfig(),
        Kernel::container(),
    );
    $encoder = new JsonEncoder();
    $server->setEncoder('json', $encoder);

    return $server;
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    unset($_GET['method'], $_GET['name'], $_POST['method'], $_POST['name']);
    $_POST = [];
    Kernel::reset();
});

test('handleRequest sends an INVALID_METHOD error when no method name is present', function (): void {
    $_GET = [];
    $_POST = [];
    $server = pwgRestRequestHandlerTestServer();
    $handler = new RestRequestHandler();

    $response = $handler->handleRequest($server);

    expect($response->getStatusCode())
        ->toBe(501)
        ->and((string) $response->getBody())
        ->toBe('{"stat":"fail","err":501,"message":"Missing \"method\" name"}');
});

test('handleRequest invokes the requested GET method with its params and sends the real response', function (): void {
    $_GET = [
        'method' => 'test.echo',
        'name' => 'Alps',
    ];
    $_POST = [];
    $server = pwgRestRequestHandlerTestServer();
    $server->register(MethodDefinition::forLegacyCallback(
        name: 'test.echo',
        callback: fn (array $params): array => [
            'name' => $params['name'],
        ],
        params: [
            ParamDefinition::required('name'),
        ],
    ));
    $handler = new RestRequestHandler();

    $response = $handler->handleRequest($server);

    expect($response->getStatusCode())
        ->toBe(200)
        ->and((string) $response->getBody())
        ->toBe('{"stat":"ok","result":{"name":"Alps"}}');
});

test('handleRequest reads params from $_POST instead of $_GET when the request is a POST', function (): void {
    $_GET = [];
    $_POST = [
        'method' => 'test.echo',
        'name' => 'Pyrenees',
    ];
    $server = pwgRestRequestHandlerTestServer();
    $server->register(MethodDefinition::forLegacyCallback(
        name: 'test.echo',
        callback: fn (array $params): array => [
            'name' => $params['name'],
        ],
        params: [
            ParamDefinition::required('name'),
        ],
    ));
    $handler = new RestRequestHandler();

    $response = $handler->handleRequest($server);

    expect($response->getStatusCode())
        ->toBe(200)
        ->and((string) $response->getBody())
        ->toBe('{"stat":"ok","result":{"name":"Pyrenees"}}');
});
