<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Controller\Api;

use Nyholm\Psr7\ServerRequest;
use Piwigo\Controller\Api\VersionController;
use Piwigo\Core\AppInfo;

test('returns the app version as a JSON object, no envelope', function (): void {
    $response = new VersionController()
        ->__invoke(new ServerRequest('GET', '/api/v1/version'));

    expect($response->getStatusCode())
        ->toBe(200);
    expect($response->getHeaderLine('Content-Type'))
        ->toBe('application/json');
    expect(json_decode((string) $response->getBody(), true))
        ->toBe([
            'version' => AppInfo::VERSION,
        ]);
});
