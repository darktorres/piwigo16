<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Controller\TestErrorsController;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Paths;

/**
 * Piwigo\Controller\TestErrorsController -- only 1 constructor dep, no
 * template rendering at all. No dedicated Integration/Browser spec of
 * its own (this exact route is what IntegrationTestCase::assertNoPhpErrors()
 * calls against a real running server, not something re-exercised via a
 * Browser test).
 */
test('__invoke returns 404 outside test mode', function (): void {
    $original = $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null;
    unset($_SERVER['HTTP_X_PIWIGO_ENV']);

    try {
        $controller = new TestErrorsController(new ErrorCollector(new DeploymentPolicy(), Paths::fromRoot(sys_get_temp_dir())));

        $response = $controller(new ServerRequest('GET', '/__test/errors'));

        expect($response->getStatusCode())->toBe(404)
            ->and((string) $response->getBody())->toBe('');
    } finally {
        if ($original === null) {
            unset($_SERVER['HTTP_X_PIWIGO_ENV']);
        } else {
            $_SERVER['HTTP_X_PIWIGO_ENV'] = $original;
        }
    }
});

test('__invoke drains and returns the collected errors as JSON in test mode', function (): void {
    $original = $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null;
    $_SERVER['HTTP_X_PIWIGO_ENV'] = 'test';

    try {
        $errorCollector = new ErrorCollector(new DeploymentPolicy(), Paths::fromRoot(sys_get_temp_dir()));
        $controller = new TestErrorsController($errorCollector);

        $response = $controller(new ServerRequest('GET', '/__test/errors'));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getHeaderLine('Content-Type'))->toContain('application/json')
            ->and((string) $response->getBody())->toBe('{"errors":[]}');
    } finally {
        if ($original === null) {
            unset($_SERVER['HTTP_X_PIWIGO_ENV']);
        } else {
            $_SERVER['HTTP_X_PIWIGO_ENV'] = $original;
        }
    }
});
