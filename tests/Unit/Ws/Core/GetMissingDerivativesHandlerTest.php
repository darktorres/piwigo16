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
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\Core\GetMissingDerivativesHandler;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Core\GetMissingDerivativesHandler -- `pwg.getMissingDerivatives`
 * (admin_only). Resolved via `Kernel::container()->get()`, same
 * rationale as GetListHandlerTest.php (Comments).
 *
 * Covers the pure "no requested type matches a real defined type" guard
 * -- checked before any real ImageService/DB call.
 */
function pwgCoreGetMissingDerivativesHandlerTestSubject(): GetMissingDerivativesHandler
{
    $handler = Kernel::container()->get(GetMissingDerivativesHandler::class);
    if (! $handler instanceof GetMissingDerivativesHandler) {
        throw new LogicException('Container returned an unexpected type for ' . GetMissingDerivativesHandler::class);
    }

    return $handler;
}

function pwgCoreGetMissingDerivativesHandlerTestServer(): Server
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

    return new Server(new EventDispatcher(), $accessControl, new ApiKeyRequestFlag(), $currentConfig, Kernel::container());
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('getMissingDerivatives rejects a types list with no real defined type match', function (): void {
    $handler = pwgCoreGetMissingDerivativesHandlerTestSubject();
    $server = pwgCoreGetMissingDerivativesHandlerTestServer();

    $result = $handler([
        'types' => ['not-a-real-type'],
        'ids' => [],
        'max_urls' => 200,
        'prev_page' => null,
        'f_min_rate' => null,
        'f_max_rate' => null,
        'f_min_hit' => null,
        'f_max_hit' => null,
        'f_min_ratio' => null,
        'f_max_ratio' => null,
        'f_max_level' => null,
        'f_min_date_available' => null,
        'f_max_date_available' => null,
        'f_min_date_created' => null,
        'f_max_date_created' => null,
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(WsError::InvalidParam->value)
            ->and($result->message())
            ->toBe('Invalid types');
    }
});
