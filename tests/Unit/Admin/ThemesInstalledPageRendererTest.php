<?php

declare(strict_types=1);

use Piwigo\Admin\ThemesInstalledPageRenderer;

/**
 * ThemesInstalledPageRenderer::render() itself needs a real DB connection,
 * template engine, and at least a second real theme on disk to exercise its
 * per-theme loop body meaningfully (see tests/Browser/ThemesInstalledPageRendererTest.php
 * for that coverage, and its own docblock for why writing a second theme
 * under the live, Apache-shared themes/ root is out of scope) -- but its
 * own compareThemes() is pure array-math with no DB/template/global state,
 * directly testable in isolation via reflection, the same pattern
 * tests/Unit/Admin/CatModifyPageRendererTest.php uses for getMinLocalDir().
 */
/**
 * @param  array<string, mixed>  $a
 * @param  array<string, mixed>  $b
 */
function callCompareThemes(array $a, array $b): int
{
    $method = new ReflectionMethod(ThemesInstalledPageRenderer::class, 'compareThemes');
    $instance = new ReflectionClass(ThemesInstalledPageRenderer::class)->newInstanceWithoutConstructor();

    /** @var int */
    return $method->invoke($instance, $a, $b);
}

test('the default theme always sorts first regardless of its own state or name', function (): void {
    $default = ['IS_DEFAULT' => true, 'STATE' => 'inactive', 'NAME' => 'zzz-theme'];
    $other = ['IS_DEFAULT' => false, 'STATE' => 'active', 'NAME' => 'aaa-theme'];

    expect(callCompareThemes($default, $other))->toBe(-1)
        ->and(callCompareThemes($other, $default))->toBe(1);
});

test('an active theme sorts before an inactive theme of the same non-default status', function (): void {
    $active = ['IS_DEFAULT' => false, 'STATE' => 'active', 'NAME' => 'zzz-theme'];
    $inactive = ['IS_DEFAULT' => false, 'STATE' => 'inactive', 'NAME' => 'aaa-theme'];

    expect(callCompareThemes($active, $inactive))->toBe(-1)
        ->and(callCompareThemes($inactive, $active))->toBe(1);
});

test('same-state themes tie-break on a case-insensitive name comparison', function (): void {
    $lower = ['IS_DEFAULT' => false, 'STATE' => 'active', 'NAME' => 'alpha'];
    $upperLater = ['IS_DEFAULT' => false, 'STATE' => 'active', 'NAME' => 'BETA'];

    expect(callCompareThemes($lower, $upperLater))->toBeLessThan(0)
        ->and(callCompareThemes($upperLater, $lower))->toBeGreaterThan(0);
});

test('identical state and name compare equal', function (): void {
    $a = ['IS_DEFAULT' => false, 'STATE' => 'active', 'NAME' => 'same-name'];
    $b = ['IS_DEFAULT' => false, 'STATE' => 'active', 'NAME' => 'same-name'];

    expect(callCompareThemes($a, $b))->toBe(0);
});

test('an unrecognized STATE value falls back to the same sort weight as inactive', function (): void {
    // $s only maps 'active'=>0/'inactive'=>1; any other string (or a
    // missing key entirely) reads through the `?? 1` fallback -- confirmed
    // by direct read of compareThemes()'s own $s lookup.
    $unrecognized = ['IS_DEFAULT' => false, 'STATE' => 'quarantined', 'NAME' => 'zzz'];
    $inactive = ['IS_DEFAULT' => false, 'STATE' => 'inactive', 'NAME' => 'aaa'];

    // Both resolve to weight 1, so this falls through to the name
    // tie-break ('aaa' < 'zzz') rather than a state-based ordering.
    expect(callCompareThemes($unrecognized, $inactive))->toBeGreaterThan(0);
});

test('a missing STATE key on both sides is treated as the empty string, not a crash', function (): void {
    $a = ['IS_DEFAULT' => false, 'NAME' => 'aaa'];
    $b = ['IS_DEFAULT' => false, 'NAME' => 'zzz'];

    expect(callCompareThemes($a, $b))->toBeLessThan(0);
});
