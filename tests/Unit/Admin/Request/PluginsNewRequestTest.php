<?php

declare(strict_types=1);

use Piwigo\Admin\Request\PluginsNewRequest;

test('fromArray returns null revision/extension/pluginId and false flags when absent', function (): void {
    $request = PluginsNewRequest::fromArray([]);

    expect($request->revision)->toBeNull()
        ->and($request->extension)->toBeNull()
        ->and($request->installStatusPresent)->toBeFalse()
        ->and($request->installStatus)->toBe('')
        ->and($request->pluginId)->toBeNull()
        ->and($request->betaTest)->toBeFalse();
});

test('fromArray parses revision and extension when both are strings', function (): void {
    $request = PluginsNewRequest::fromArray(['revision' => '42', 'extension' => '7']);

    expect($request->revision)->toBe('42')
        ->and($request->extension)->toBe('7');
});

test('fromArray nulls revision when extension is missing', function (): void {
    $request = PluginsNewRequest::fromArray(['revision' => '42']);

    expect($request->revision)->toBe('42')
        ->and($request->extension)->toBeNull();
});

test('fromArray reports installStatusPresent and the raw string value', function (): void {
    $request = PluginsNewRequest::fromArray(['installstatus' => 'ok']);

    expect($request->installStatusPresent)->toBeTrue()
        ->and($request->installStatus)->toBe('ok');
});

test('fromArray normalizes a non-string installstatus to an empty string but still reports present', function (): void {
    $request = PluginsNewRequest::fromArray(['installstatus' => ['x']]);

    expect($request->installStatusPresent)->toBeTrue()
        ->and($request->installStatus)->toBe('');
});

test('fromArray parses plugin_id as a string', function (): void {
    $request = PluginsNewRequest::fromArray(['plugin_id' => 'my_plugin']);

    expect($request->pluginId)->toBe('my_plugin');
});

test('fromArray nulls a non-string plugin_id', function (): void {
    $request = PluginsNewRequest::fromArray(['plugin_id' => ['x']]);

    expect($request->pluginId)->toBeNull();
});

test('fromArray sets betaTest true only for the literal string true', function (): void {
    expect(PluginsNewRequest::fromArray(['beta-test' => 'true'])->betaTest)->toBeTrue()
        ->and(PluginsNewRequest::fromArray(['beta-test' => 'false'])->betaTest)->toBeFalse()
        ->and(PluginsNewRequest::fromArray([])->betaTest)->toBeFalse();
});
