<?php

declare(strict_types=1);

use Piwigo\Admin\Maintenance\ServerInfoService;

test('curatedInfo reports the real running PHP/SAPI/OS', function (): void {
    $info = new ServerInfoService()->curatedInfo();

    expect($info->phpVersion)->toBe(PHP_VERSION)
        ->and($info->sapi)->toBe(PHP_SAPI)
        ->and($info->os)->toBe(PHP_OS);
});

test('curatedInfo lists real loaded extensions', function (): void {
    $info = new ServerInfoService()->curatedInfo();

    // Every PHP install running this test suite has at least 'Core'
    // loaded; asserting a fixed, real extension name (not a count) avoids
    // the test being fragile to the exact extension set.
    expect($info->extensions)->toContain('Core');
});

test('curatedInfo does not leak a full phpinfo() dump', function (): void {
    // SEC-22's whole point: no server filesystem paths, no environment
    // variables, no per-module build configuration -- only the fields
    // this class explicitly curates.
    $info = new ServerInfoService()->curatedInfo();

    expect(array_keys(get_object_vars($info)))->toBe(['phpVersion', 'sapi', 'os', 'extensions', 'ini']);
});

test('renderHtml embeds the curated info and escapes it', function (): void {
    $html = new ServerInfoService()->renderHtml();

    expect($html)->toContain(htmlspecialchars(PHP_VERSION))
        ->toContain('Loaded extensions')
        ->not->toContain('phpinfo()');
});

test('curatedInfo sorts the loaded extensions', function (): void {
    $info = new ServerInfoService()->curatedInfo();

    $sorted = $info->extensions;
    sort($sorted);

    expect($info->extensions)->toBe($sorted);
});

test('curatedInfo reports every curated ini setting, not an empty set', function (): void {
    $info = new ServerInfoService()->curatedInfo();

    expect(array_keys($info->ini))->toBe([
        'memory_limit',
        'upload_max_filesize',
        'post_max_size',
        'max_execution_time',
        'max_input_time',
        'max_file_uploads',
        'default_charset',
        'date.timezone',
        'display_errors',
    ]);
});

test('renderHtml renders every accumulated section of the page, not just the last write', function (): void {
    $service = new ServerInfoService();
    $info = $service->curatedInfo();
    $html = $service->renderHtml();

    expect($html)->toContain('<!DOCTYPE html>')
        ->toContain('<h1>Server information</h1>')
        ->toContain('<table border="1" cellpadding="4">')
        ->toContain('<tr><th>PHP version</th><td>' . htmlspecialchars($info->phpVersion) . '</td></tr>')
        ->toContain('<tr><th>SAPI</th><td>' . htmlspecialchars($info->sapi) . '</td></tr>')
        ->toContain('<tr><th>OS</th><td>' . htmlspecialchars($info->os) . '</td></tr>')
        ->toContain('</table>')
        ->toContain('<h2>Loaded extensions</h2><ul>')
        ->toContain('</ul></body></html>');

    foreach ($info->ini as $setting => $value) {
        expect($html)->toContain('<tr><th>' . htmlspecialchars($setting) . '</th><td>' . htmlspecialchars($value) . '</td></tr>');
    }

    foreach ($info->extensions as $extension) {
        expect($html)->toContain('<li>' . htmlspecialchars($extension) . '</li>');
    }
});

test('renderHtml escapes an ini value containing real HTML metacharacters', function (): void {
    // display_errors is the one KEY_INI_SETTINGS entry that accepts an
    // arbitrary freeform string via ini_set() at runtime (the others --
    // default_charset, date.timezone, the *_limit/*_time/*_uploads
    // integers -- are all validated and reject a raw HTML payload), so it's
    // the only one that can carry a real escaping-relevant value for this
    // test. Restored immediately after use.
    $previous = ini_get('display_errors');
    ini_set('display_errors', '<script>alert(1)</script>&"\'');

    try {
        $html = new ServerInfoService()->renderHtml();

        expect($html)->toContain(htmlspecialchars('<script>alert(1)</script>&"\''))
            ->not->toContain('<script>alert(1)</script>');
    } finally {
        ini_set('display_errors', $previous);
    }
});
