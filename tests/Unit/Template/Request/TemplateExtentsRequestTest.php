<?php

declare(strict_types=1);

use Piwigo\Template\Request\TemplateExtentsRequest;

test('fromArray returns empty string for an empty GET', function (): void {
    $request = TemplateExtentsRequest::fromArray([]);

    expect($request->keysConcatenated)->toBe('');
});

test('fromArray concatenates every GET key', function (): void {
    $request = TemplateExtentsRequest::fromArray(['admin' => '1', 'foo' => '2']);

    expect($request->keysConcatenated)->toBe('adminfoo');
});

test('fromArray stringifies a purely-numeric key', function (): void {
    $request = TemplateExtentsRequest::fromArray(['1' => 'value']);

    expect($request->keysConcatenated)->toBe('1');
});
