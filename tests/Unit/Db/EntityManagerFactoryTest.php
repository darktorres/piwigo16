<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Db\EntityManagerFactory;

/**
 * EntityManagerFactory::build() is used pervasively across this suite as a
 * transitive dependency, but nothing directly asserted its own custom
 * Doctrine-type-registration guard (lines 39-50) -- an in-memory pdo_sqlite
 * connection (same technique as tests/Unit/Db/BatchWriterTest.php) keeps
 * this at the Unit tier without touching real DB credentials; building an
 * EntityManager doesn't open a connection either way (see this class's own
 * docblock).
 */
test('build() returns a real EntityManager and registers every custom Doctrine type it depends on', function (): void {
    // Kills line 47's RemoveNot (`if (Type::hasType($name))` instead of
    // `! Type::hasType($name)`) and line 48's RemoveMethodCall (dropping
    // the addType() call entirely). Both leave every one of these 6
    // types permanently unregistered (RemoveNot's guard can never
    // become true if addType() is only ever called once it's already
    // true) -- confirmed live real code leaves all 6 registered after a
    // single build() call.
    $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

    $em = EntityManagerFactory::build($conn);

    expect($em)->toBeInstanceOf(EntityManagerInterface::class);
    foreach (['group_id', 'user_id', 'category_id', 'ip_address', 'comment_id', 'tag_id'] as $customType) {
        expect(Type::hasType($customType))->toBeTrue("type '{$customType}' should be registered");
    }
});

test('build() is safe to call more than once without throwing on double type registration', function (): void {
    $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

    EntityManagerFactory::build($conn);
    $second = EntityManagerFactory::build($conn);

    expect($second)->toBeInstanceOf(EntityManagerInterface::class);
});

test('build() configures a real entity source path, not an empty one', function (): void {
    // Kills line 53's RemoveArrayItem (`paths: []` instead of
    // `paths: [dirname(__DIR__)]`). getClassMetadata() for an
    // explicitly-named class works identically either way (the
    // attribute driver reflects the given class directly, regardless of
    // configured paths), so this can only be distinguished through a
    // directory-wide metadata operation. Confirmed live:
    // getAllMetadata() finds 28 real entities with the real path, and
    // throws a genuine Doctrine MappingException ("Specifying source
    // file paths... is required") with an empty one.
    $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

    $em = EntityManagerFactory::build($conn);

    expect($em->getMetadataFactory()->getAllMetadata())->not->toBeEmpty();
});

test('build() uses the given connection, not a freshly built one', function (): void {
    // Kills line 58's CoalesceRemoveLeft (`DbConnection::build()`
    // instead of `$conn ?? DbConnection::build()`) -- confirmed live
    // that real code's returned EntityManager wraps the EXACT connection
    // instance passed in, not a different one silently built from real
    // env credentials.
    $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

    $em = EntityManagerFactory::build($conn);

    expect($em->getConnection())->toBe($conn);
});

/**
 * Line 48's RemoveMethodCall (dropping `Type::addType($name, $class);`
 * entirely) is a genuine, reproducible `pest --mutate` tool blind spot,
 * NOT an unaddressed gap: re-applying this exact mutation directly to
 * the source file and running the full `vendor/bin/pest --testsuite
 * Unit --parallel` suite (not just this file) makes the "registers
 * every custom Doctrine type" test above fail every time, confirmed
 * across 3 separate live reruns -- yet `pest --mutate`'s own scan
 * reports it UNTESTED consistently, even after the fix that killed
 * line 47's sibling RemoveNot mutation on the very same guard. Distinct
 * from the already-documented proc_open() subprocess-invisibility
 * blind spot (feedback_pest_mutate_invisible_to_subprocess_tests) --
 * no subprocess is involved here; root cause not identified, only the
 * discrepancy between the tool's report and directly-observed real
 * behavior.
 *
 * Confirmed-equivalent (in this environment): line 54's TrueToFalse
 * (`isDevMode: false` instead of `true`). ORMSetup::createConfig()'s
 * cache-adapter selection for isDevMode=false falls through APCu ->
 * Memcached -> Redis -> ArrayAdapter; this environment has none of
 * apcu/memcached/redis available (confirmed live), so it lands on the
 * exact same ArrayAdapter fallback as isDevMode=true's own direct
 * ArrayAdapter choice. Confirmed live: getConfiguration()->getMetadataCache()
 * returns the identical adapter class either way.
 */

/**
 * entityMtimeHash() (private, memoized) has no public seam to observe
 * short of recomputing its own intended algorithm against the real
 * composer classmap/filesystem and comparing exact values via
 * reflection -- the same technique this method's own docblock describes
 * it replacing (a RecursiveDirectoryIterator walk), just run once here
 * as a correctness oracle instead of on every request.
 */
test('entityMtimeHash() hashes only Piwigo *Entity.php mtimes, filtered and sorted', function (): void {
    // Kills: ForeachEmptyIterable (`foreach ([] as ...)` instead of
    // `foreach ($classMap as ...)`) and RemoveFunctionCall (dropping
    // `sort($mtimes);`) directly -- both would only match this
    // independently-recomputed expected hash by coincidence.
    // Kills: IfNegated and BooleanAndToBooleanOr on the
    // `str_starts_with(...) && str_ends_with(...)` filter, plus
    // StrStartsWithToStrEndsWith / StrEndsWithToStrStartsWith swaps on
    // its two operands -- confirmed live the real classmap has ~840
    // `Piwigo\` classes and only ~39 end in `Entity.php`, so AND vs OR
    // (or swapping which function checks which operand) selects a wildly
    // different file set and therefore a different hash.
    // Kills: IfNegated and NotIdenticalToIdentical on
    // `if ($mtime !== false)` -- both invert the guard to "only keep
    // entries where filemtime() failed", which for the real, existing
    // entity files on disk is always empty.
    $sourceFile = (new ReflectionClass(EntityManagerFactory::class))->getFileName();
    expect($sourceFile)->not->toBeFalse();

    /** @var array<string, string> $classMap */
    $classMap = require dirname((string) $sourceFile, 4) . '/vendor/composer/autoload_classmap.php';

    $expectedMtimes = [];
    foreach ($classMap as $class => $file) {
        if (str_starts_with($class, 'Piwigo\\') && str_ends_with($file, 'Entity.php')) {
            $mtime = filemtime($file);
            if ($mtime !== false) {
                $expectedMtimes[] = $mtime;
            }
        }
    }
    sort($expectedMtimes);

    // Sanity check on the test's own fixture data, not the mutation
    // target: if this ever goes empty the test below would trivially
    // pass against md5(''), so assert real entity files really matched.
    expect($expectedMtimes)->not->toBeEmpty();

    $expectedHash = md5(implode(',', $expectedMtimes));

    $method = new ReflectionMethod(EntityManagerFactory::class, 'entityMtimeHash');
    $actualHash = $method->invoke(null);

    expect($actualHash)->toBe($expectedHash);
});
