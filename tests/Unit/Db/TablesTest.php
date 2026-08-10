<?php

declare(strict_types=1);

use Piwigo\Db\Tables;

test('every static method returns its bare snake_case table name', function (): void {
    $reflection = new ReflectionClass(Tables::class);
    $methods = array_filter(
        $reflection->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === Tables::class
            && $m->isStatic() && $m->isPublic(),
    );

    expect($methods)->not->toBeEmpty();

    foreach ($methods as $method) {
        $expectedName = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $method->getName()));
        $result = $method->invoke(null);

        expect($result)->toBe($expectedName);
    }
});
