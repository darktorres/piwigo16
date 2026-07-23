<?php

declare(strict_types=1);

use Piwigo\Core\ErrorCollector;

/**
 * Deliberately never calls ErrorCollector::install() -- it registers a real
 * set_error_handler()/register_shutdown_function() pair, and PHP has no way
 * to unregister a shutdown function once added, which would leak into every
 * later test in this shared PHPUnit/Pest process. drain()'s own return-then-
 * clear contract is exercised directly via reflection instead, matching how
 * this class's other static state has no install()-dependent test either.
 */
/**
 * @param list<string> $entries
 */
function seedCollected(array $entries): void
{
    $prop = new ReflectionProperty(ErrorCollector::class, 'collected');
    $prop->setValue(null, $entries);
}

beforeEach(function (): void {
    ErrorCollector::reset();
});

afterEach(function (): void {
    ErrorCollector::reset();
});

test('drain returns an empty array when nothing was collected', function (): void {
    expect(ErrorCollector::drain())->toBe([]);
});

test('drain returns exactly what was collected', function (): void {
    seedCollected(['[WARNING] foo in bar.php:1', '[NOTICE] baz in qux.php:2']);

    expect(ErrorCollector::drain())->toBe(['[WARNING] foo in bar.php:1', '[NOTICE] baz in qux.php:2']);
});

test('drain clears the buffer, unlike collected()', function (): void {
    seedCollected(['[WARNING] foo in bar.php:1']);

    ErrorCollector::drain();

    expect(ErrorCollector::collected())->toBe([]);
});

test('a second drain after a first returns empty', function (): void {
    seedCollected(['[WARNING] foo in bar.php:1']);

    ErrorCollector::drain();

    expect(ErrorCollector::drain())->toBe([]);
});
