<?php

declare(strict_types=1);

use Piwigo\Routing\RouteMatchStatus;
use Piwigo\Routing\RouteResult;

test('found carries the handler and args', function (): void {
    $result = RouteResult::found('Some\\Handler', [
        'id' => '5',
    ]);

    expect($result->status)
        ->toBe(RouteMatchStatus::Found);
    expect($result->handler)
        ->toBe('Some\\Handler');
    expect($result->args)
        ->toBe([
            'id' => '5',
        ]);
});

test('notFound carries no handler or args', function (): void {
    $result = RouteResult::notFound();

    expect($result->status)
        ->toBe(RouteMatchStatus::NotFound);
    expect($result->handler)
        ->toBeNull();
    expect($result->args)
        ->toBe([]);
});

test('methodNotAllowed carries no handler or args', function (): void {
    $result = RouteResult::methodNotAllowed();

    expect($result->status)
        ->toBe(RouteMatchStatus::MethodNotAllowed);
    expect($result->handler)
        ->toBeNull();
    expect($result->args)
        ->toBe([]);
});
