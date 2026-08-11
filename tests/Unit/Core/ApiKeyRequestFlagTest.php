<?php

declare(strict_types=1);

use Piwigo\Core\ApiKeyRequestFlag;

test('activate sets the flag; a fresh instance starts unset', function (): void {
    $flag = new ApiKeyRequestFlag();

    expect($flag->isActive())
        ->toBeFalse();

    $flag->activate();

    expect($flag->isActive())
        ->toBeTrue();
});
