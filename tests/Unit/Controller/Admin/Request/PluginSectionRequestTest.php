<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\PluginSectionRequest;
use Piwigo\Validation\InputValidator;

test('fromArray extracts the plugin id from AdminShell\'s own <id>/admin.php alias shape', function (): void {
    $request = PluginSectionRequest::fromArray([
        'section' => 'my-plugin/admin.php',
    ], new InputValidator());

    expect($request->pluginId)
        ->toBe('my-plugin');
});

test('fromArray accepts a bare plugin id with no trailing segment', function (): void {
    $request = PluginSectionRequest::fromArray([
        'section' => 'my-plugin',
    ], new InputValidator());

    expect($request->pluginId)
        ->toBe('my-plugin');
});

test('fromArray ignores everything after the first segment', function (): void {
    $request = PluginSectionRequest::fromArray([
        'section' => 'my-plugin/sub/admin.php?tab=x',
    ], new InputValidator());

    expect($request->pluginId)
        ->toBe('my-plugin');
});

test('fromArray rejects a missing section param', function (): void {
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray([], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects an empty section param', function (): void {
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray([
        'section' => '',
    ], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a plugin id with a path-traversal segment', function (): void {
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray([
        'section' => '../../etc/passwd',
    ], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a plugin id with an unsafe character', function (): void {
    // '.' is rejected by plugin_id's own \w-only pattern even though it
    // would be a syntactically valid leading path segment.
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray([
        'section' => 'my.plugin/admin.php',
    ], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a non-string section param', function (): void {
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray([
        'section' => [
            'nested' => 'array',
        ],
    ], new InputValidator()))
        ->toThrow(RuntimeException::class);
});
