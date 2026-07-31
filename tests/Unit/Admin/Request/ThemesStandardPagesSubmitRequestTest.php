<?php

declare(strict_types=1);

use Piwigo\Admin\Request\ThemesStandardPagesSubmitRequest;

const THEMES_STD_PAGES_LOGO_OPTIONS = ['piwigo_logo', 'custom_logo', 'gallery_title', 'none'];
const THEMES_STD_PAGES_SKIN_OPTIONS = ['default', 'cobalt', 'green'];

test('fromArrays reports not submitted when the submit key is absent', function (): void {
    $request = ThemesStandardPagesSubmitRequest::fromArrays([], [], THEMES_STD_PAGES_LOGO_OPTIONS, THEMES_STD_PAGES_SKIN_OPTIONS);

    expect($request->isSubmitted)->toBeFalse();
});

test('fromArrays treats a missing use_standard_pages as unchecked', function (): void {
    $request = ThemesStandardPagesSubmitRequest::fromArrays(['submit' => ''], [], THEMES_STD_PAGES_LOGO_OPTIONS, THEMES_STD_PAGES_SKIN_OPTIONS);

    expect($request->useStandardPages)->toBeFalse();
});

test('fromArrays treats "0" as unchecked and any other value as checked', function (): void {
    $unchecked = ThemesStandardPagesSubmitRequest::fromArrays(['use_standard_pages' => '0'], [], THEMES_STD_PAGES_LOGO_OPTIONS, THEMES_STD_PAGES_SKIN_OPTIONS);
    $checked = ThemesStandardPagesSubmitRequest::fromArrays(['use_standard_pages' => '1'], [], THEMES_STD_PAGES_LOGO_OPTIONS, THEMES_STD_PAGES_SKIN_OPTIONS);

    expect($unchecked->useStandardPages)->toBeFalse()
        ->and($checked->useStandardPages)->toBeTrue();
});

test('fromArrays accepts a logo/skin matching the given option lists', function (): void {
    $request = ThemesStandardPagesSubmitRequest::fromArrays([
        'std_pgs_display_logo' => 'custom_logo',
        'std_pgs_selected_skin' => 'cobalt',
    ], [], THEMES_STD_PAGES_LOGO_OPTIONS, THEMES_STD_PAGES_SKIN_OPTIONS);

    expect($request->selectedLogo)->toBe('custom_logo')
        ->and($request->selectedSkin)->toBe('cobalt');
});

test('fromArrays returns null logo/skin for an unrecognized value', function (): void {
    $request = ThemesStandardPagesSubmitRequest::fromArrays([
        'std_pgs_display_logo' => 'evil_logo',
        'std_pgs_selected_skin' => 'evil_skin',
    ], [], THEMES_STD_PAGES_LOGO_OPTIONS, THEMES_STD_PAGES_SKIN_OPTIONS);

    expect($request->selectedLogo)->toBeNull()
        ->and($request->selectedSkin)->toBeNull();
});

test('fromArrays recognizes a real logo upload', function (): void {
    $request = ThemesStandardPagesSubmitRequest::fromArrays([], [
        'std_pgs_logo' => ['tmp_name' => '/tmp/phpXXXX', 'name' => 'my logo.png'],
    ], THEMES_STD_PAGES_LOGO_OPTIONS, THEMES_STD_PAGES_SKIN_OPTIONS);

    expect($request->logoTmpName)->toBe('/tmp/phpXXXX')
        ->and($request->logoName)->toBe('my logo.png');
});

test('fromArrays reports no upload when tmp_name is empty', function (): void {
    $request = ThemesStandardPagesSubmitRequest::fromArrays([], [
        'std_pgs_logo' => ['tmp_name' => '', 'name' => 'my logo.png'],
    ], THEMES_STD_PAGES_LOGO_OPTIONS, THEMES_STD_PAGES_SKIN_OPTIONS);

    expect($request->logoTmpName)->toBeNull()
        ->and($request->logoName)->toBe('');
});

test('fromArrays accepts a real tmp_name but falls back to an empty logoName when name is absent', function (): void {
    $request = ThemesStandardPagesSubmitRequest::fromArrays([], [
        'std_pgs_logo' => ['tmp_name' => '/tmp/phpXXXX'],
    ], THEMES_STD_PAGES_LOGO_OPTIONS, THEMES_STD_PAGES_SKIN_OPTIONS);

    expect($request->logoTmpName)->toBe('/tmp/phpXXXX')
        ->and($request->logoName)->toBe('');
});

test('fromArrays accepts a real tmp_name but falls back to an empty logoName when name is not a string', function (): void {
    $request = ThemesStandardPagesSubmitRequest::fromArrays([], [
        // A real multipart upload always sends 'name' as a string --
        // this is PHP's own $_FILES superglobal shape, taken here as a
        // plain array<array-key, mixed>, so a non-string 'name' is still
        // a genuine input this method's own type checks must reject
        // without a TypeError.
        'std_pgs_logo' => ['tmp_name' => '/tmp/phpYYYY', 'name' => 12345],
    ], THEMES_STD_PAGES_LOGO_OPTIONS, THEMES_STD_PAGES_SKIN_OPTIONS);

    expect($request->logoTmpName)->toBe('/tmp/phpYYYY')
        ->and($request->logoName)->toBe('');
});

test('fromArrays reports no upload when the file field is absent', function (): void {
    $request = ThemesStandardPagesSubmitRequest::fromArrays([], [], THEMES_STD_PAGES_LOGO_OPTIONS, THEMES_STD_PAGES_SKIN_OPTIONS);

    expect($request->logoTmpName)->toBeNull();
});
