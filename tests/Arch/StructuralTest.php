<?php

declare(strict_types=1);

// P6: real structural rules for src/Piwigo/, replacing the P0 placeholder.

arch()->expect('Piwigo')->toUseStrictTypes();

/**
 * PHPStan (2.2.5, this project's pinned version) has no
 * checkMissingOverrideMethodAttribute parameter, and pest-plugin-arch has no
 * built-in "#[\Override] required" expectation — confirmed by grepping both
 * vendored packages. Reflection-based check instead.
 *
 * @param class-string $fqcn
 * @return list<string> violation messages, one per method missing #[\Override]
 */
function findMissingOverrideAttributes(string $fqcn): array
{
    $violations = [];
    $reflection = new ReflectionClass($fqcn);

    foreach ($reflection->getMethods() as $method) {
        if ($method->getDeclaringClass()->getName() !== $fqcn) {
            continue; // inherited, not (re)declared on this class
        }

        if ($method->getName() === '__construct') {
            continue; // constructors are exempt by PHP's own #[\Override] convention
        }

        $overridesSomething = false;

        $parent = $reflection->getParentClass();
        if ($parent !== false && $parent->hasMethod($method->getName())) {
            $overridesSomething = true;
        }

        foreach ($reflection->getInterfaceNames() as $interfaceName) {
            if ((new ReflectionClass($interfaceName))->hasMethod($method->getName())) {
                $overridesSomething = true;
            }
        }

        if ($overridesSomething && $method->getAttributes(Override::class) === []) {
            $violations[] = "{$fqcn}::{$method->getName()}()";
        }
    }

    return $violations;
}

test('findMissingOverrideAttributes() flags a missing #[\Override]', function (): void {
    interface StructuralTestFixtureInterface
    {
        public function run(): void;
    }

    $withoutAttribute = new class () implements StructuralTestFixtureInterface {
        public function run(): void {}
    };

    expect(findMissingOverrideAttributes($withoutAttribute::class))->toBe([$withoutAttribute::class . '::run()']);
});

test('findMissingOverrideAttributes() accepts a present #[\Override]', function (): void {
    interface StructuralTestFixtureInterface2
    {
        public function run(): void;
    }

    $withAttribute = new class () implements StructuralTestFixtureInterface2 {
        #[Override]
        public function run(): void {}
    };

    expect(findMissingOverrideAttributes($withAttribute::class))->toBe([]);
});

test('every Piwigo\ class under src/Piwigo/ has #[\Override] on every overriding/implementing method', function (): void {
    $root = __DIR__ . '/../../src/Piwigo';
    $violations = [];

    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file RecursiveIteratorIterator loses this over RecursiveDirectoryIterator */
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $fqcn = 'Piwigo\\' . str_replace(['/', '.php'], ['\\', ''], $relative);

            if (! class_exists($fqcn) && ! interface_exists($fqcn) && ! trait_exists($fqcn)) {
                continue;
            }

            if (! class_exists($fqcn)) {
                continue; // interfaces/traits can't override anything themselves
            }

            $violations = [...$violations, ...findMissingOverrideAttributes($fqcn)];
        }
    }

    expect($violations)->toBe([]);
});
