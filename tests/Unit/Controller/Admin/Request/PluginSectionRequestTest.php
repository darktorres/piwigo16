<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\PluginSectionRequest;

test('fromArray parses plugin id and sections from a well-formed section param', function (): void {
    $request = PluginSectionRequest::fromArray(['section' => 'my-plugin/admin.php']);

    expect($request->pluginId)->toBe('my-plugin')
        ->and($request->sections)->toBe(['my-plugin', 'admin.php']);
});

test('fromArray filters out empty segments from consecutive slashes', function (): void {
    // Regression coverage for the real denial-of-service bug documented on
    // PluginSectionRequest's own docblock: a naive unset()-during-iteration
    // loop used to hang forever on a middle empty segment.
    $request = PluginSectionRequest::fromArray(['section' => 'my-plugin//admin.php']);

    expect($request->sections)->toBe(['my-plugin', 'admin.php']);
});

test('fromArray accepts a deeper path with more than 2 segments', function (): void {
    $request = PluginSectionRequest::fromArray(['section' => 'my-plugin/sub/admin.php']);

    expect($request->pluginId)->toBe('my-plugin')
        ->and($request->sections)->toBe(['my-plugin', 'sub', 'admin.php']);
});

test('fromArray rejects a missing section param', function (): void {
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray([]))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a section param with a single segment', function (): void {
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray(['section' => 'my-plugin']))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a path-traversal segment', function (): void {
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray(['section' => '../../etc/passwd']))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a segment with an unsafe character', function (): void {
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray(['section' => 'my-plugin/admin;rm.php']))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a plugin id with an unsafe character even when the segment charset would allow it', function (): void {
    // '.' is allowed in a general segment (matches the file-extension use
    // case) but not in plugin_id itself.
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray(['section' => 'my.plugin/admin.php']))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a non-string section param', function (): void {
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray(['section' => ['nested' => 'array']]))
        ->toThrow(RuntimeException::class);
});
