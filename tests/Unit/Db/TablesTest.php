<?php

declare(strict_types=1);

use Piwigo\Config\Config;
use Piwigo\Db\Tables;

beforeEach(function (): void {
    Config::reset();
});

afterEach(function (): void {
    Config::reset();
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
    Config::loadArray(['db_prefix' => 'custom_']);

    expect(Tables::images())->toBe('custom_images')
        ->and(Tables::userInfos())->toBe('custom_user_infos');
});
