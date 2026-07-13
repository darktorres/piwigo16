<?php

declare(strict_types=1);

use Piwigo\Admin\Maintenance\ServerInfoService;

test('curatedInfo reports the real running PHP/SAPI/OS', function (): void {
    $info = new ServerInfoService()->curatedInfo();

    expect($info['php_version'])->toBe(PHP_VERSION)
        ->and($info['sapi'])->toBe(PHP_SAPI)
        ->and($info['os'])->toBe(PHP_OS);
});

test('curatedInfo lists real loaded extensions', function (): void {
    $info = new ServerInfoService()->curatedInfo();

    // Every PHP install running this test suite has at least 'Core'
    // loaded; asserting a fixed, real extension name (not a count) avoids
    // the test being fragile to the exact extension set.
    expect($info['extensions'])->toContain('Core');
});

test('curatedInfo does not leak a full phpinfo() dump', function (): void {
    // SEC-22's whole point: no server filesystem paths, no environment
    // variables, no per-module build configuration -- only the fields
    // this class explicitly curates.
    $info = new ServerInfoService()->curatedInfo();

    expect(array_keys($info))->toBe(['php_version', 'sapi', 'os', 'extensions', 'ini']);
});

test('renderHtml embeds the curated info and escapes it', function (): void {
    $html = new ServerInfoService()->renderHtml();

    expect($html)->toContain(htmlspecialchars(PHP_VERSION))
        ->toContain('Loaded extensions')
        ->not->toContain('phpinfo()');
});
