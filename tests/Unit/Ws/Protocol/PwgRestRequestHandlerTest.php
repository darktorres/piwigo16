<?php

declare(strict_types=1);

use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
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
use Piwigo\Ws\Protocol\PwgJsonEncoder;
use Piwigo\Ws\Protocol\PwgRestRequestHandler;
use Piwigo\Ws\PwgServer;

/**
 * Piwigo\Ws\Protocol\PwgRestRequestHandler -- the sole real
 * `PwgRequestHandler` implementation wired by `WsInitializer::init()`.
 * No dedicated Integration/Browser spec of its own -- every real WS
 * HTTP request reaches it transitively through `WsController`.
 *
 * `handleRequest()`'s own `$_GET`/`$_POST` reads go through
 * `WsRawRequest::fromGlobals()`, already covered directly by
 * `WsRawRequestTest.php` for the raw method-name/param-bag extraction
 * logic -- this file only covers the 2 real branches specific to
 * `handleRequest()` itself: no method name resolved at all, and a real
 * method dispatched through to `PwgServer::sendResponse()`.
 *
 * `sendResponse()` writes its encoded body via `print_r()` -- captured
 * here with `ob_start()`/`ob_get_clean()` rather than asserting on
 * `PwgServer::invoke()`'s return value directly, since `handleRequest()`
 * itself never exposes that return value to a caller.
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
        theme: '',
        status: UserStatus::Admin,
        enabledHigh: false,
    ));

    return new AccessControl(
        HtmlServiceTestFactory::build(),
        new AccessControlTestFakeRedirectServiceNeverCalled(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );
}

function pwgRestRequestHandlerTestServer(): PwgServer
{
    $server = new PwgServer(
        new EventDispatcher(),
        pwgRestRequestHandlerTestAccessControl(),
        new ApiKeyRequestFlag(),
        new CurrentConfig(),
    );
    $encoder = new PwgJsonEncoder();
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
    $handler = new PwgRestRequestHandler();

    ob_start();
    $handler->handleRequest($server);
    $output = ob_get_clean();

    expect($output)->toBe('{"stat":"fail","err":501,"message":"Missing \"method\" name"}');
});

test('handleRequest invokes the requested GET method with its params and sends the real response', function (): void {
    $_GET = [
        'method' => 'test.echo',
        'name' => 'Alps',
    ];
    $_POST = [];
    $server = pwgRestRequestHandlerTestServer();
    $server->addMethod('test.echo', fn (array $params, PwgServer &$service): array => ['name' => $params['name']], ['name']);
    $handler = new PwgRestRequestHandler();

    ob_start();
    $handler->handleRequest($server);
    $output = ob_get_clean();

    expect($output)->toBe('{"stat":"ok","result":{"name":"Alps"}}');
});

test('handleRequest reads params from $_POST instead of $_GET when the request is a POST', function (): void {
    $_GET = [];
    $_POST = [
        'method' => 'test.echo',
        'name' => 'Pyrenees',
    ];
    $server = pwgRestRequestHandlerTestServer();
    $server->addMethod('test.echo', fn (array $params, PwgServer &$service): array => ['name' => $params['name']], ['name']);
    $handler = new PwgRestRequestHandler();

    ob_start();
    $handler->handleRequest($server);
    $output = ob_get_clean();

    expect($output)->toBe('{"stat":"ok","result":{"name":"Pyrenees"}}');
});
