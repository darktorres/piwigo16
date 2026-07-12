<?php

declare(strict_types=1);

use Piwigo\Section\SectionContext;
use Piwigo\Section\SectionContextRegistry;

beforeEach(function (): void {
    SectionContextRegistry::reset();
});

afterEach(function (): void {
    SectionContextRegistry::reset();
});

test('current returns null before anything is set', function (): void {
    expect(SectionContextRegistry::current())->toBeNull();
});

test('set stores the context and current returns the same instance', function (): void {
    $context = new SectionContext('../', '/category/1', ['category', '1'], 1, null, null, []);

    SectionContextRegistry::set($context);

    expect(SectionContextRegistry::current())->toBe($context);
});

test('set overwrites a previously stored context', function (): void {
    $first = new SectionContext('../', '/category/1', ['category', '1'], 1, null, null, []);
    $second = new SectionContext('../../', '/category/2', ['category', '2'], 1, null, null, []);

    SectionContextRegistry::set($first);
    SectionContextRegistry::set($second);

    expect(SectionContextRegistry::current())->toBe($second);
});

test('reset clears the stored context', function (): void {
    SectionContextRegistry::set(new SectionContext('../', '/category/1', ['category', '1'], 1, null, null, []));

    SectionContextRegistry::reset();

    expect(SectionContextRegistry::current())->toBeNull();
});
