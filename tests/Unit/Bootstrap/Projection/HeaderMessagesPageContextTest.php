<?php

declare(strict_types=1);

use Piwigo\Bootstrap\Projection\HeaderMessagesPageContext;

test('toArray flattens the header messages list', function (): void {
    expect(new HeaderMessagesPageContext(['Message one', 'Message two'])->toArray())
        ->toBe([
            'header_msgs' => ['Message one', 'Message two'],
        ]);
});
