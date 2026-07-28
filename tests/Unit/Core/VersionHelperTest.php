<?php

declare(strict_types=1);

use Piwigo\Core\VersionHelper;

/**
 * Piwigo\Core\VersionHelper -- had zero dedicated coverage (see
 * /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1).
 */
test('safeVersionCompare returns the raw -1/0/1 result when no operator is given', function (): void {
    expect(VersionHelper::safeVersionCompare('1.0.0', '1.0.1'))->toBe(-1);
    expect(VersionHelper::safeVersionCompare('1.0.1', '1.0.0'))->toBe(1);
    expect(VersionHelper::safeVersionCompare('1.0.0', '1.0.0'))->toBe(0);
});

test('safeVersionCompare returns a bool when a known operator is given', function (): void {
    expect(VersionHelper::safeVersionCompare('1.0.0', '1.0.1', '<'))->toBeTrue();
    expect(VersionHelper::safeVersionCompare('1.0.0', '1.0.1', '>'))->toBeFalse();
    expect(VersionHelper::safeVersionCompare('1.0.0', '1.0.0', '=='))->toBeTrue();
});

test('safeVersionCompare falls back to the raw -1/0/1 result for an unrecognized operator', function (): void {
    expect(VersionHelper::safeVersionCompare('1.0.0', '1.0.1', 'not-a-real-operator'))->toBe(-1);
});

test('safeVersionCompare inserts a dot before a trailing letter group, matching version_compare()\'s own convention', function (): void {
    expect(VersionHelper::safeVersionCompare('11.0.0a', '11.0.0b'))->toBe(-1);
});

test('safeVersionCompare converts a single trailing letter to its ordinal value', function (): void {
    expect(VersionHelper::safeVersionCompare('1.0.a', '1.0.b'))->toBe(-1);
});

test('getBranchFromVersion takes only the first dot-separated segment', function (): void {
    expect(VersionHelper::getBranchFromVersion('11.1.2'))->toBe('11');
    expect(VersionHelper::getBranchFromVersion('17.0'))->toBe('17');
    expect(VersionHelper::getBranchFromVersion('17'))->toBe('17');
});
