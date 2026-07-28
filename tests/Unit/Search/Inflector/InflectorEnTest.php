<?php

declare(strict_types=1);

use Piwigo\Search\Inflector\Inflector_en;

/**
 * Piwigo\Search\Inflector\Inflector_en::get_variants() -- the quick-search
 * word-stemming engine (generates plural/singular/verb-form variants of a
 * search term so e.g. searching "cat" also matches "cats"). Had zero
 * dedicated coverage (see /home/torres/.claude/plans/piped-enchanting-
 * spark.md, Wave 1) despite being pure, deterministic, side-effect-free
 * logic. Every expected value below was independently confirmed by
 * invoking the real class before writing the assertion (this engine's
 * multi-stage regex fallthrough is not something to hand-trace and trust
 * blindly).
 */
test('a regular singular word gets its "s"-suffixed plural as a variant', function (): void {
    $inflector = new Inflector_en();

    expect($inflector->get_variants('cat'))->toBe(['cats']);
});

test('a regular plural word gets its singular as a variant', function (): void {
    $inflector = new Inflector_en();

    expect($inflector->get_variants('cats'))->toBe(['cat']);
});

test('an irregular exception word maps directly to its counterpart', function (): void {
    $inflector = new Inflector_en();

    expect($inflector->get_variants('man'))->toBe(['men']);
    expect($inflector->get_variants('men'))->toBe(['man']);
});

test('an uncountable exception word (0 in the exceptions map) has no variants', function (): void {
    $inflector = new Inflector_en();

    expect($inflector->get_variants('fish'))->toBe([]);
});

test('a consonant+y word pluralizes via the "ies" rule', function (): void {
    $inflector = new Inflector_en();

    expect($inflector->get_variants('city'))->toBe(['cities']);
    expect($inflector->get_variants('cities'))->toBe(['city']);
});

test('an x/ch/ss/sh-ending word pluralizes via the "es" rule', function (): void {
    $inflector = new Inflector_en();

    expect($inflector->get_variants('box'))->toBe(['boxes']);
    expect($inflector->get_variants('boxes'))->toBe(['box']);
});

test('a hive/quiz/bus/octopus-style word uses its dedicated pluralization rule', function (): void {
    $inflector = new Inflector_en();

    expect($inflector->get_variants('hive'))->toBe(['hives']);
    expect($inflector->get_variants('quiz'))->toBe(['quizzes']);
    expect($inflector->get_variants('bus'))->toBe(['buses']);
    expect($inflector->get_variants('octopus'))->toBe(['octopuses']);
});

test('a word over 4 chars ending in "er" also gets an -ing variant (er2ing branch)', function (): void {
    $inflector = new Inflector_en();

    // 'runner' (6 chars, > 4 and > 5) exercises both the pluralizer and
    // the er2ing branch: 'runners' (plural) then 'running' (er->ing).
    expect($inflector->get_variants('runner'))->toBe(['runners', 'running']);
});

test('a word over 5 chars ending in "ing" also gets an -er variant plus that variant\'s own plural (ing2er branch)', function (): void {
    $inflector = new Inflector_en();

    // 'running' (7 chars): the generic pluralizer fallback ('runnings'),
    // then ing2er ('runner'), then the pluralizer re-applied to that new
    // 'runner' variant ('runners').
    expect($inflector->get_variants('running'))->toBe(['runnings', 'runner', 'runners']);
});

test('a short word (<=4 chars) never reaches the er2ing branch', function (): void {
    $inflector = new Inflector_en();

    // 'car' (3 chars) only gets the plain pluralizer variant -- too short
    // for either the er2ing or ing2er branch to run at all.
    expect($inflector->get_variants('car'))->toBe(['cars']);
});
