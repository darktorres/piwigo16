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

        if ($reflection->isEnum() && in_array($method->getName(), ['cases', 'from', 'tryFrom'], true)) {
            continue; // compiler-synthesized from the implicit UnitEnum/BackedEnum interface -- cannot bear attributes
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
    // Reuses a built-in interface (rather than declaring a new named
    // fixture interface here) so this file has nothing for
    // `composer dump-autoload --strict-psr` to flag as PSR-4-noncompliant.
    $withoutAttribute = new class () implements Countable {
        public function count(): int
        {
            return 0;
        }
    };

    expect(findMissingOverrideAttributes($withoutAttribute::class))->toBe([$withoutAttribute::class . '::count()']);
});

test('findMissingOverrideAttributes() accepts a present #[\Override]', function (): void {
    $withAttribute = new class () implements Countable {
        #[Override]
        public function count(): int
        {
            return 0;
        }
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

// P7: Kernel::container() is a service locator -- services must receive
// dependencies via constructor injection instead. It exists only to let
// Bootstrap/ and the root index.php reach into the container before
// injection is possible. docs/PLAN-REPLAY.md: "Gate: arch test enforcing
// this boundary from P7 onward, not deferred to P32."

/**
 * @return list<array{path: string, line: int}>
 */
function findCallSites(string $dir, string $needle): array
{
    $hits = [];
    if (!is_dir($dir)) {
        return $hits;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file RecursiveIteratorIterator loses this over RecursiveDirectoryIterator */
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $lines = file($file->getPathname());
        foreach ($lines !== false ? $lines : [] as $lineNumber => $line) {
            if (str_contains($line, $needle)) {
                $hits[] = ['path' => $file->getPathname(), 'line' => $lineNumber + 1];
            }
        }
    }

    return $hits;
}

/**
 * @return list<array{path: string, line: int}>
 */
function findCallSitesInRootPhpFiles(string $root, string $needle): array
{
    $hits = [];
    $paths = glob($root . '/*.php');
    foreach ($paths !== false ? $paths : [] as $pathname) {
        $lines = file($pathname);
        foreach ($lines !== false ? $lines : [] as $lineNumber => $line) {
            if (str_contains($line, $needle)) {
                $hits[] = ['path' => $pathname, 'line' => $lineNumber + 1];
            }
        }
    }

    return $hits;
}

/**
 * P12: bin/piwigo has no .php extension, so it's invisible to
 * findCallSitesInRootPhpFiles()'s glob('*.php'). Scans bin/* explicitly so
 * a future second bin/ script can't bypass the same locator boundary rules
 * root index.php and src/Piwigo/ are already held to.
 *
 * @return list<array{path: string, line: int}>
 */
function findCallSitesInBinFiles(string $root, string $needle): array
{
    $hits = [];
    $paths = glob($root . '/bin/*');
    foreach ($paths !== false ? $paths : [] as $pathname) {
        if (! is_file($pathname)) {
            continue;
        }

        $lines = file($pathname);
        foreach ($lines !== false ? $lines : [] as $lineNumber => $line) {
            if (str_contains($line, $needle)) {
                $hits[] = ['path' => $pathname, 'line' => $lineNumber + 1];
            }
        }
    }

    return $hits;
}

/**
 * @param list<array{path: string, line: int}> $hits
 * @return list<string>
 */
function describeCallSites(array $hits): array
{
    return array_map(static fn (array $hit): string => "{$hit['path']}:{$hit['line']}", $hits);
}

test('Kernel::container() is only called from src/Piwigo/Bootstrap/ and root index.php', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'Kernel::container('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'Kernel::container('),
        ...findCallSitesInBinFiles($repoRoot, 'Kernel::container('),
    ];

    $disallowed = array_values(array_filter(
        $hits,
        static fn (array $hit): bool => ! str_contains($hit['path'], '/src/Piwigo/Bootstrap/')
            && basename($hit['path']) !== 'index.php'
    ));

    expect(describeCallSites($disallowed))->toBe([]);
});

test('Container::build() is only called from src/Piwigo/Core/Kernel.php', function (): void {
    // Mirrors the Kernel::container() boundary rule above: production code
    // only ever reaches the DI container through Kernel::boot(), never by
    // building one directly. Tests are exempt (not scanned) -- they need to
    // call Container::build() directly to exercise the extraDefinitions
    // override mechanism.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'Container::build('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'Container::build('),
        ...findCallSitesInBinFiles($repoRoot, 'Container::build('),
    ];

    $disallowed = array_values(array_filter(
        $hits,
        static fn (array $hit): bool => ! str_ends_with($hit['path'], '/src/Piwigo/Core/Kernel.php')
    ));

    expect(describeCallSites($disallowed))->toBe([]);
});

test('Kernel::reset() is only called from tests/', function (): void {
    // reset() exists purely so tests can isolate Kernel's static state between
    // cases; production code must never touch it (src/Piwigo/ and the root
    // entry points are scanned -- tests/ itself is deliberately not scanned,
    // since that's the one place calls are expected).
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'Kernel::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'Kernel::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'Kernel::reset('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('ShutdownHandler::reset() is only called from tests/', function (): void {
    // Mirrors the Kernel::reset() rule above -- reset() exists purely for
    // test isolation between cases; production code must never touch it.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'ShutdownHandler::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'ShutdownHandler::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'ShutdownHandler::reset('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('RequestFactory, ResponseEmitter, CommonBootstrap, and the P9 middleware/pipeline/routing classes declare only readonly state', function (): void {
    // SEC-60 (worker-isolation, partial verification): these classes must stay
    // free of MUTABLE state so a future FrankenPHP worker loop can reuse them
    // across requests without cross-request state bleed. readonly properties
    // set once at construction and never mutated are not a bleed risk --
    // refined from P7's "zero properties allowed" rule, which was too strict
    // for P9's middleware (SecurityHeadersMiddleware/RoutingMiddleware/
    // ControllerInvokerMiddleware/MiddlewarePipeline legitimately need
    // constructor-injected readonly properties to function). Kernel's own
    // static state is the sanctioned exception -- it's request-isolated via
    // Kernel::reset(), which a worker loop will call between requests once
    // that mode exists.
    foreach ([
        Piwigo\Http\RequestFactory::class,
        Piwigo\Http\ResponseEmitter::class,
        Piwigo\Http\ResponseFactory::class,
        Piwigo\Http\MiddlewarePipeline::class,
        Piwigo\Http\BaselineSecurityHeaders::class,
        Piwigo\Http\Middleware\ExceptionHandlerMiddleware::class,
        Piwigo\Http\Middleware\SecurityHeadersMiddleware::class,
        Piwigo\Http\Middleware\RoutingMiddleware::class,
        Piwigo\Http\Middleware\ControllerInvokerMiddleware::class,
        Piwigo\Routing\Router::class,
        Piwigo\Routing\RouteResult::class,
        Piwigo\Bootstrap\CommonBootstrap::class,
        Piwigo\Bootstrap\RequestPipeline::class,
    ] as $fqcn) {
        $mutableProperties = array_filter(
            (new ReflectionClass($fqcn))->getProperties(),
            static fn (ReflectionProperty $property): bool => ! $property->isReadOnly()
        );
        $violations = array_map(
            static fn (ReflectionProperty $property): string => "{$fqcn}::\${$property->getName()}",
            $mutableProperties
        );

        expect(array_values($violations))->toBe([]);
    }
});
