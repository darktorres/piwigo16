<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\WebVitalMetric;

test('toArray maps every property to its logging-context key', function (): void {
    $metric = new WebVitalMetric(
        name: 'LCP',
        value: 1234.5,
        id: 'v1-abc123',
        rating: 'good',
        url: 'https://example.test/index.php',
    );

    expect($metric->toArray())
        ->toBe([
            'name' => 'LCP',
            'value' => 1234.5,
            'id' => 'v1-abc123',
            'rating' => 'good',
            'url' => 'https://example.test/index.php',
        ]);
});
