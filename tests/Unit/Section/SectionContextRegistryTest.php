<?php

declare(strict_types=1);

use Piwigo\Common\Enum\Section;
use Piwigo\Section\SectionContext;
use Piwigo\Section\SectionContextRegistry;

// SectionContextRegistry is normally shared via the DI container; each
// test constructs its own fresh instance directly, so no reset() between
// tests is needed.

test('current returns null before anything is set', function (): void {
    $registry = new SectionContextRegistry();

    expect($registry->current())
        ->toBeNull();
});

test('set stores the context and current returns the same instance', function (): void {
    $registry = new SectionContextRegistry();
    $context = new SectionContext(section: Section::Categories, rootPath: '../');

    $registry->set($context);

    expect($registry->current())
        ->toBe($context);
});

test('set overwrites a previously stored context', function (): void {
    $registry = new SectionContextRegistry();
    $first = new SectionContext(section: Section::Categories, rootPath: '../');
    $second = new SectionContext(section: Section::Tags, rootPath: '../../');

    $registry->set($first);
    $registry->set($second);

    expect($registry->current())
        ->toBe($second);
});

test('reset clears the stored context', function (): void {
    $registry = new SectionContextRegistry();
    $registry->set(new SectionContext(section: Section::Categories, rootPath: '../'));

    $registry->reset();

    expect($registry->current())
        ->toBeNull();
});
