<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\PluginSectionRequest;
use Piwigo\Validation\InputValidator;

/**
 * A mutation-testing sweep found the plugin_id-specific validate() call's
 * own `mandatory=true` flag is a confirmed-equivalent mutant: by the time
 * $plugin_id ($sections[0]) reaches that call, it has already passed
 * through the per-segment loop above with mandatory=true, which only
 * throws when InputValidator::emptyValue() is true -- so any value that
 * reaches this second call is already proven non-empty by that same
 * (pure, deterministic) function, making the second mandatory flag's own
 * "value is empty" branch permanently unreachable here.
 */
test('fromArray parses plugin id and sections from a well-formed section param', function (): void {
    $request = PluginSectionRequest::fromArray(['section' => 'my-plugin/admin.php'], new InputValidator());

    expect($request->pluginId)->toBe('my-plugin')
        ->and($request->sections)->toBe(['my-plugin', 'admin.php']);
});

test('fromArray filters out empty segments from consecutive slashes', function (): void {
    // Regression coverage for the real denial-of-service bug documented on
    // PluginSectionRequest's own docblock: a naive unset()-during-iteration
    // loop used to hang forever on a middle empty segment.
    $request = PluginSectionRequest::fromArray(['section' => 'my-plugin//admin.php'], new InputValidator());

    expect($request->sections)->toBe(['my-plugin', 'admin.php']);
});

test('fromArray accepts a deeper path with more than 2 segments', function (): void {
    $request = PluginSectionRequest::fromArray(['section' => 'my-plugin/sub/admin.php'], new InputValidator());

    expect($request->pluginId)->toBe('my-plugin')
        ->and($request->sections)->toBe(['my-plugin', 'sub', 'admin.php']);
});

test('fromArray rejects a missing section param with the exact "section" message', function (): void {
    // The generic RuntimeException class assertion alone can't tell this
    // apart from a mutated fallback ('' -> a non-empty placeholder):
    // that placeholder would become a single, real segment that instead
    // fails the per-segment loop's own pattern check, throwing the same
    // exception class but with a "segment" (not "section") message.
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray([], new InputValidator()))
        ->toThrow(RuntimeException::class, '[Hacking attempt] the input parameter "section" is not valid');
});

test('fromArray rejects a section param with a single segment', function (): void {
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray(['section' => 'my-plugin'], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a path-traversal segment', function (): void {
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray(['section' => '../../etc/passwd'], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a segment with an unsafe character', function (): void {
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray(['section' => 'my-plugin/admin;rm.php'], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a plugin id with an unsafe character even when the segment charset would allow it', function (): void {
    // '.' is allowed in a general segment (matches the file-extension use
    // case) but not in plugin_id itself.
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray(['section' => 'my.plugin/admin.php'], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a non-string section param with the exact "section" message', function (): void {
    // Same reasoning as the missing-param test above, but for line 76's
    // own separate is_string() fallback.
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray(['section' => ['nested' => 'array']], new InputValidator()))
        ->toThrow(RuntimeException::class, '[Hacking attempt] the input parameter "section" is not valid');
});

test('fromArray rejects a ".." segment that is not the plugin id itself', function (): void {
    // "fromArray rejects a path-traversal segment" above uses '..' as
    // the *first* segment (plugin_id), which also fails plugin_id's own
    // separate, stricter \w-only pattern -- both the real '..' check and
    // a mutated (removed) version of it still throw for that input, via
    // different checks, indistinguishable through a generic exception
    // assertion. '..' does match the general segment's own looser
    // pattern (dots are allowed there), so putting it in a *later*
    // segment isolates the dedicated check: without it, this would
    // construct successfully instead of throwing.
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray(['section' => 'my-plugin/..'], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a "0" segment as a known, documented empty-value edge case', function (): void {
    // Kills the segment validate() call's own mandatory=true flag: '0'
    // passes the general segment pattern but is treated as an empty
    // value by InputValidator::emptyValue() (this class's own docblock
    // documents this exact trade-off) -- mandatory=true is what turns
    // that into a real rejection instead of a silent pass-through.
    expect(fn (): PluginSectionRequest => PluginSectionRequest::fromArray(['section' => 'my-plugin/0'], new InputValidator()))
        ->toThrow(RuntimeException::class, '[Hacking attempt] the input parameter "segment" is not valid');
});
