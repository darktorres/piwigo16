<?php

declare(strict_types=1);

use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\Event\WsAddMethods;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsDefaultMethods;

/**
 * Piwigo\Ws\WsDefaultMethods -- the thin coordinator that delegates to one
 * *MethodRegistrar per handler-directory domain (P25 Stage 1 split every
 * `pwg.*` registration out of this class's own former 1,322-line
 * register() body) -- so this isn't branch-coverage in the usual sense,
 * it's a real regression guard on the single place every WS method's
 * `admin_only`/`post_only` security flag is declared, now spread across
 * 13 files instead of one. The one real conditional in the whole
 * registration surface, `$this->accessControl->isAGuest() ? 'guest' :
 * $this->currentUser->get()->username` (ImagesMethodRegistrar's default
 * `author` value for `pwg.images.addComment`'s own registration -- not
 * asserted on directly here), needs the container-shared `CurrentUser`
 * initialized even though this test never calls `addComment()` itself,
 * since `register()` evaluates that ternary eagerly while building the
 * registration, not lazily inside a callback.
 * A silent drop of `admin_only` from a sensitive method here (e.g.
 * during a future refactor) is a real security bug nothing else in this
 * codebase would catch. No dedicated Integration/Browser spec of its
 * own -- every `tests/Contract/*` WS test exercises one real method's
 * registration transitively, never this file's own registration logic
 * as a whole.
 *
 * Resolved via `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`) -- 13 constructor deps (one per domain
 * registrar), each autowired with whatever shared collaborators
 * (CurrentConfig/AccessControl/CurrentUser/ImageStdParams) its own
 * domain's registrations actually need, none of which matter for what's
 * asserted here.
 */
function wsDefaultMethodsTestServer(): Server
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
    $accessControl = new AccessControl(
        HtmlServiceTestFactory::build(),
        new AccessControlTestFakeRedirectServiceNeverCalled(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );

    return new Server(new EventDispatcher(), $accessControl, new ApiKeyRequestFlag(), $currentConfig);
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(1),
        username: Username::from('fixture_admin'),
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Admin,
        enabledHigh: false,
    ));
});

afterEach(function (): void {
    Kernel::reset();
});

test('register wires up a large, representative set of real pwg.* methods', function (): void {
    $registrar = Kernel::container()->get(WsDefaultMethods::class);
    if (! $registrar instanceof WsDefaultMethods) {
        throw new LogicException('Container returned an unexpected type for ' . WsDefaultMethods::class);
    }
    $server = wsDefaultMethodsTestServer();

    $registrar->register(new WsAddMethods($server));

    foreach (['pwg.getVersion', 'pwg.categories.getList', 'pwg.categories.getImages', 'pwg.images.getInfo', 'pwg.tags.getList', 'pwg.groups.getList', 'pwg.users.getList', 'pwg.session.login', 'pwg.session.logout', 'pwg.plugins.performAction', 'pwg.themes.performAction'] as $methodName) {
        expect($server->hasMethod($methodName))
            ->toBeTrue("expected {$methodName} to be registered");
    }
});

test('register marks pwg.plugins.performAction/pwg.themes.performAction admin_only, with no post_only restriction', function (): void {
    $registrar = Kernel::container()->get(WsDefaultMethods::class);
    if (! $registrar instanceof WsDefaultMethods) {
        throw new LogicException('Container returned an unexpected type for ' . WsDefaultMethods::class);
    }
    $server = wsDefaultMethodsTestServer();

    $registrar->register(new WsAddMethods($server));

    expect($server->getMethodOptions('pwg.plugins.performAction'))
        ->toBe([
            'admin_only' => true,
        ])
        ->and($server->getMethodOptions('pwg.themes.performAction'))
        ->toBe([
            'admin_only' => true,
        ]);
});

test('register marks pwg.users.setInfo both admin_only and post_only', function (): void {
    $registrar = Kernel::container()->get(WsDefaultMethods::class);
    if (! $registrar instanceof WsDefaultMethods) {
        throw new LogicException('Container returned an unexpected type for ' . WsDefaultMethods::class);
    }
    $server = wsDefaultMethodsTestServer();

    $registrar->register(new WsAddMethods($server));

    expect($server->getMethodOptions('pwg.users.setInfo'))
        ->toBe([
            'admin_only' => true,
            'post_only' => true,
        ]);
});

test('register marks pwg.session.login post_only, with no admin_only restriction', function (): void {
    $registrar = Kernel::container()->get(WsDefaultMethods::class);
    if (! $registrar instanceof WsDefaultMethods) {
        throw new LogicException('Container returned an unexpected type for ' . WsDefaultMethods::class);
    }
    $server = wsDefaultMethodsTestServer();

    $registrar->register(new WsAddMethods($server));

    expect($server->getMethodOptions('pwg.session.login'))
        ->toBe([
            'post_only' => true,
        ]);
});

test('register leaves pwg.categories.getList unrestricted (no admin_only/post_only)', function (): void {
    $registrar = Kernel::container()->get(WsDefaultMethods::class);
    if (! $registrar instanceof WsDefaultMethods) {
        throw new LogicException('Container returned an unexpected type for ' . WsDefaultMethods::class);
    }
    $server = wsDefaultMethodsTestServer();

    $registrar->register(new WsAddMethods($server));

    expect($server->getMethodOptions('pwg.categories.getList'))
        ->toBe([]);
});

test('register merges the shared f_* filter params into pwg.categories.getImages\' signature', function (): void {
    $registrar = Kernel::container()->get(WsDefaultMethods::class);
    if (! $registrar instanceof WsDefaultMethods) {
        throw new LogicException('Container returned an unexpected type for ' . WsDefaultMethods::class);
    }
    $server = wsDefaultMethodsTestServer();

    $registrar->register(new WsAddMethods($server));

    $signature = $server->getMethodSignature('pwg.categories.getImages');

    expect($signature)
        ->toHaveKeys(['cat_id', 'recursive', 'per_page', 'page', 'order', 'f_min_rate', 'f_max_rate', 'f_min_hit', 'f_max_hit', 'f_min_ratio', 'f_max_ratio', 'f_max_level', 'f_min_date_available', 'f_max_date_available', 'f_min_date_created', 'f_max_date_created']);
});
