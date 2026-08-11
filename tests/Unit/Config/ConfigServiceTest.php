<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;

// confGetParam() for a property-backed key reads via reflection on
// CurrentConfig's own getter and never touches $repo at all -- constructing
// a real ConfigRepository still stays Unit-tier regardless, since DBAL
// connections are lazy (no socket opens until a query executes) and
// EntityManager::getRepository() only reads ClassMetadata (parsed from
// attributes, no I/O). DB-touching methods (loadConfFromDb()/
// confUpdateParam()/confDeleteParam()/pwgIsDbconfWriteable()) are covered
// by tests/Integration/ConfigServiceTest.php instead.
//
// A mutation-testing sweep found 2 confirmed-equivalent mutants in
// hydrate() itself, both stemming from the same fact: every CurrentConfig
// setter (grepped across the whole class, 288 of them) takes a plain,
// always-named (nullable or not) bool/int/float/string/array parameter --
// none is genuinely untyped or union-typed.
// - `$paramType instanceof \ReflectionNamedType` mutated to force-`true`:
//   unobservable, since this condition is already always true for every
//   real setter -- forcing it doesn't change anything. (A mutated
//   force-`false` or negated ternary, by contrast, IS real and caught by
//   the tests below: it would make $paramTypeName always resolve to null,
//   skipping every coercion arm.)
// - `$paramType?->allowsNull()`: since $paramType is never PHP-null,
//   the null-safe operator behaves identically to a bare `->` for every
//   reachable input.
// Also: `ucfirst($propertyName)` in both hydrate() and confDeleteParam()
// is unobservable via ReflectionMethod -- PHP resolves method names
// case-insensitively, confirmed live (`new ReflectionMethod($class,
// 'setblkmenubar')` still finds/invokes setBlkMenubar()). And the
// `(float) $decoded` cast for an int $decoded is unobservable too:
// invoking a float-typed parameter via Reflection with a raw int still
// auto-widens to float (PHP's own strict_types exception for numeric
// widening applies the same way through Reflection), confirmed live.
function unconnectedConfigService(): ConfigService
{
    $connection = DriverManager::getConnection([
        'driver' => 'mysqli',
        'user' => '',
        'password' => '',
        'dbname' => '',
        'host' => 'localhost',
    ]);
    $repo = EntityManagerFactory::build($connection)->getRepository(ConfigEntry::class);

    return new ConfigService($repo, new EventDispatcher(), CurrentConfigTestFactory::get());
}

beforeEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
});

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
});

test('confGetParam reads a property-backed key via its own typed getter', function (): void {
    CurrentConfigTestFactory::get()->blkMenubar = [
        'menu' => 50,
    ];

    $service = unconnectedConfigService();

    expect($service->confGetParam('blk_menubar'))
        ->toBe([
            'menu' => 50,
        ]);
});

// confGetParam()'s fallback behavior for a genuinely dynamic (no-property)
// key needs a real DB connection -- unlike the property-backed path above,
// it reads straight from the repository on a miss, not a pure in-memory
// lookup. Covered by tests/Integration/ConfigServiceTest.php instead.

/**
 * hydrate() is `private` and only reachable through loadConfFromDb()
 * (DB-bound) in production, but its own body never touches the DB or
 * $this->repo at all -- pure reflection + json_decode + type coercion
 * against CurrentConfig's own setters, so it's invoked here directly via
 * Reflection instead, matching this repo's established
 * `invokeXxx()`-via-Reflection convention for private-static pure logic
 * (see e.g. TotpTest.php's own invokeGenerateCodeFromTimestamp()).
 *
 * hydrate() is `private` (instance), not `private static` -- ConfigService
 * reads and writes CurrentConfig through a constructor-injected instance,
 * not a static call, so hydrate() can't be invoked with a null $object.
 * unconnectedConfigService() itself resolves CurrentConfigTestFactory::get(),
 * matching every test below that also reads/writes through
 * CurrentConfigTestFactory::get() directly.
 */
function invokeConfigServiceHydrate(string $param, ?string $raw): void
{
    new ReflectionMethod(ConfigService::class, 'hydrate')->invoke(unconnectedConfigService(), $param, $raw);
}

function jsonEncodeForHydrateTest(mixed $value): string
{
    $encoded = json_encode($value);
    if ($encoded === false) {
        throw new LogicException('json_encode() failed for a hydrate() test fixture value');
    }
    return $encoded;
}

test('hydrate falls back to false for a non-bool decoded value on a bool-typed property', function (): void {
    CurrentConfigTestFactory::get()->galleryLocked = true;

    invokeConfigServiceHydrate('gallery_locked', jsonEncodeForHydrateTest('not-a-bool'));

    expect(CurrentConfigTestFactory::get()->galleryLocked)->toBe(false);
});

test('hydrate falls back to exactly 0 for a non-int decoded value on an int-typed property', function (): void {
    CurrentConfigTestFactory::get()->sessionLength = 999;

    invokeConfigServiceHydrate('session_length', jsonEncodeForHydrateTest('not-an-int'));

    expect(CurrentConfigTestFactory::get()->sessionLength)->toBe(0);
});

test('hydrate falls back to exactly 0.0 for a non-numeric decoded value on a float-typed property', function (): void {
    CurrentConfigTestFactory::get()->nbmMaxTreatmentTimeoutPercent = 99.9;

    invokeConfigServiceHydrate('nbm_max_treatment_timeout_percent', jsonEncodeForHydrateTest('not-a-float'));

    expect(CurrentConfigTestFactory::get()->nbmMaxTreatmentTimeoutPercent)->toBe(0.0);
});

test('hydrate falls back to an empty string for a non-string decoded value on a string-typed property', function (): void {
    // Same real bug this class's own docblock references (data_dir_checked):
    // jsonEncodeForHydrateTest(123) decodes to a real int, which the 'string' match arm
    // must not silently accept.
    CurrentConfigTestFactory::get()->galleryTitle = 'Something Real';

    invokeConfigServiceHydrate('gallery_title', jsonEncodeForHydrateTest(123));

    expect(CurrentConfigTestFactory::get()->galleryTitle)->toBe('');
});

test('hydrate invokes the setter with null for a nullable property when raw is null', function (): void {
    CurrentConfigTestFactory::get()->countOrphans = 5;

    invokeConfigServiceHydrate('count_orphans', null);

    expect(CurrentConfigTestFactory::get()->countOrphans)->toBe(null);
});
