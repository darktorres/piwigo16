<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Controller;

use LogicException;
use Nyholm\Psr7\ServerRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\VitalsController;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

function vitalsTestAccessControl(): AccessControl
{
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $currentUser->set(new User(
        id: UserId::from(2),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Guest,
        enabledHigh: false,
    ));

    return new AccessControl(
        HtmlServiceTestFactory::build(),
        new AccessControlTestFakeRedirectServiceNeverCalled(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );
}

test('__invoke logs a well-formed known metric and returns an empty 204 response', function (): void {
    $logger = new VitalsTestSpyLogger();
    $controller = new VitalsController(vitalsTestAccessControl(), $logger);
    $body = json_encode([
        'name' => 'LCP',
        'value' => 1234.5,
        'id' => 'v1-abc',
        'rating' => 'good',
        'url' => 'https://example.test/',
    ]);
    if (! is_string($body)) {
        throw new LogicException('expected a JSON string');
    }
    $request = new ServerRequest('POST', '/vitals', [], $body);

    $response = $controller($request);

    expect($response->getStatusCode())
        ->toBe(204)
        ->and((string) $response->getBody())
        ->toBe('')
        ->and($logger->records)
        ->toHaveCount(1)
        ->and($logger->records[0]['message'])->toBe('web_vitals.metric')
        ->and($logger->records[0]['context'])->toBe([
            'name' => 'LCP',
            'value' => 1234.5,
            'id' => 'v1-abc',
            'rating' => 'good',
            'url' => 'https://example.test/',
        ]);
});

test('__invoke silently skips an unknown metric name and still returns 204', function (): void {
    $logger = new VitalsTestSpyLogger();
    $controller = new VitalsController(vitalsTestAccessControl(), $logger);
    $body = json_encode([
        'name' => 'NOT_A_REAL_METRIC',
        'value' => 1.0,
        'id' => 'v1',
        'rating' => 'good',
        'url' => 'https://example.test/',
    ]);
    if (! is_string($body)) {
        throw new LogicException('expected a JSON string');
    }
    $request = new ServerRequest('POST', '/vitals', [], $body);

    $response = $controller($request);

    expect($response->getStatusCode())
        ->toBe(204)
        ->and($logger->records)
        ->toBe([]);
});

test('__invoke silently skips malformed JSON and an empty body alike', function (): void {
    $logger = new VitalsTestSpyLogger();
    $controller = new VitalsController(vitalsTestAccessControl(), $logger);

    $malformed = $controller(new ServerRequest('POST', '/vitals', [], '{not valid json'));
    $empty = $controller(new ServerRequest('POST', '/vitals', [], ''));

    expect($malformed->getStatusCode())
        ->toBe(204)
        ->and($empty->getStatusCode())
        ->toBe(204)
        ->and($logger->records)
        ->toBe([]);
});
