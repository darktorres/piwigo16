<?php

declare(strict_types=1);

use Piwigo\Section\SectionContext;
use Piwigo\Section\SectionContextRegistry;

// Container-shared instance (singleton/service-locator elimination
// campaign, Phase 2) -- each test constructs its own fresh instance
// directly; no reset() needed.

test('current returns null before anything is set', function (): void {
    $registry = new SectionContextRegistry();

    expect($registry->current())->toBeNull();
});

test('set stores the context and current returns the same instance', function (): void {
    $registry = new SectionContextRegistry();
    $context = new SectionContext(section: 'categories', rootPath: '../');

    $registry->set($context);

    expect($registry->current())->toBe($context);
});

test('set overwrites a previously stored context', function (): void {
    $registry = new SectionContextRegistry();
    $first = new SectionContext(section: 'categories', rootPath: '../');
    $second = new SectionContext(section: 'tags', rootPath: '../../');

    $registry->set($first);
    $registry->set($second);

    expect($registry->current())->toBe($second);
});

test('reset clears the stored context', function (): void {
    $registry = new SectionContextRegistry();
    $registry->set(new SectionContext(section: 'categories', rootPath: '../'));

    $registry->reset();

    expect($registry->current())->toBeNull();
});
