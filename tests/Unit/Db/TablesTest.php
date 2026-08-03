<?php

declare(strict_types=1);

use Piwigo\Db\Tables;

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

    $methods = array_filter(
        $reflection->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === Tables::class,
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
