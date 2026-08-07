<?php

declare(strict_types=1);

use Piwigo\Db\DbCredentials;
use Piwigo\Db\Tables;
use Piwigo\Tests\Support\KernelContainerOverride;

// tests/bootstrap.php loads real PIWIGO_DB_* vars for the whole Pest
// process (see tests/Unit/Db/DbCredentialsTest.php's own comment) --
// save + restore PIWIGO_DB_PREFIX specifically so the "defaults to
// piwigo_" assertion below tests DbCredentials's own fallback, not a
// coincidence of the real test DB's configured prefix.
$originalPrefix = null;

beforeEach(function () use (&$originalPrefix): void {
    $value = getenv('PIWIGO_DB_PREFIX');
    $originalPrefix = $value === false ? null : $value;
    putenv('PIWIGO_DB_PREFIX');
});

afterEach(function () use (&$originalPrefix): void {
    putenv($originalPrefix === null ? 'PIWIGO_DB_PREFIX' : 'PIWIGO_DB_PREFIX=' . $originalPrefix);
});

test('every static method returns the db prefix plus its snake_case table name', function (): void {
    $reflection = new ReflectionClass(Tables::class);

    // ReflectionClass::getMethods()'s own $filter bitmask is OR-combined,
    // not AND -- a method matching *either* IS_STATIC or IS_PUBLIC passes,
    // so this needs its own explicit isStatic()/isPublic() check too.
    // Tables::dbCredentials() is a private static helper: it is static
    // but not public, and would otherwise be treated as one more
    // table-name getter here.
    $methods = array_filter(
        $reflection->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === Tables::class
            && $m->isStatic() && $m->isPublic(),
    );

    expect($methods)->not->toBeEmpty();

    foreach ($methods as $method) {
        $expectedSuffix = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $method->getName()));
        $result = $method->invoke(null);

        expect($result)->toBe('piwigo_' . $expectedSuffix);
    }
});

test('table names respect a custom db prefix', function (): void {
    putenv('PIWIGO_DB_PREFIX=custom_');

    expect(Tables::images())->toBe('custom_images')
        ->and(Tables::userInfos())->toBe('custom_user_infos');
});

test('dbCredentials resolves the container-shared instance once Kernel is booted, not a fresh env read', function (): void {
    // Kills line 39's IfNegated/InstanceOfToFalse and line 40's
    // RemoveEarlyReturn: any of those three skip this return entirely,
    // falling through to a bare DbCredentials::fromEnv() read instead --
    // which would see this test's real (untouched) PIWIGO_DB_PREFIX env
    // var, not this container-bound instance's own prefix.
    $shared = new DbCredentials(
        host: 'h',
        user: 'u',
        password: 'p',
        database: 'd',
        prefix: 'container_shared_',
    );

    KernelContainerOverride::with(
        [DbCredentials::class => $shared],
        function (): void {
            expect(Tables::images())->toBe('container_shared_images');
        }
    );
});

test('dbCredentials falls back to a fresh env read when the container binding resolves to the wrong type', function (): void {
    // Kills line 39's InstanceOfToTrue. With the real, correctly-configured
    // container this instanceof check is always true in practice, so the
    // only way to observe it actually running is to break the binding on
    // purpose -- same KernelContainerOverride::withWrongTypeFor() pattern
    // as FilesystemHelperTest's own InstanceOfToTrue-killing tests. Under
    // the mutation (`if (true)`), Tables::dbCredentials()'s own `: DbCredentials`
    // return type would reject returning the stdClass with a \TypeError
    // instead of quietly falling through to fromEnv() here.
    putenv('PIWIGO_DB_PREFIX=wrong_type_fallback_');

    KernelContainerOverride::withWrongTypeFor(
        DbCredentials::class,
        function (): void {
            expect(Tables::images())->toBe('wrong_type_fallback_images');
        }
    );
});
