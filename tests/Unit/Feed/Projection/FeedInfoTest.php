<?php

declare(strict_types=1);

use Piwigo\Feed\Projection\FeedInfo;

test('constructs with the given user id and a null last check', function (): void {
    $info = new FeedInfo(1, null);

    expect($info->userId)
        ->toBe(1)
        ->and($info->lastCheck)
        ->toBeNull();
});

test('constructs with the given user id and last check', function (): void {
    $lastCheck = new DateTimeImmutable('2024-03-05 12:34:56');

    $info = new FeedInfo(1, $lastCheck);

    expect($info->userId)
        ->toBe(1)
        ->and($info->lastCheck)
        ->toBe($lastCheck);
});
