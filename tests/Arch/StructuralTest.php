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
            if (new ReflectionClass($interfaceName)->hasMethod($method->getName())) {
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
        static fn (array $hit): bool => ! str_contains((string) $hit['path'], '/src/Piwigo/Bootstrap/')
            && basename((string) $hit['path']) !== 'index.php'
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
        static fn (array $hit): bool => ! str_ends_with((string) $hit['path'], '/src/Piwigo/Core/Kernel.php')
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

test('Config::reset() is only called from tests/', function (): void {
    // Mirrors the Kernel::reset() rule above -- reset() exists purely for
    // test isolation between cases; production code must never touch it.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'Config::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'Config::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'Config::reset('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('Config::loadArray() is only called from tests/', function (): void {
    // Same test-isolation rationale as Config::reset() above.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'Config::loadArray('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'Config::loadArray('),
        ...findCallSitesInBinFiles($repoRoot, 'Config::loadArray('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('SessionService::reset() is only called from tests/', function (): void {
    // Same test-isolation rationale as Config::reset() above.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'SessionService::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'SessionService::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'SessionService::reset('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('StorageRegistry::reset() is only called from tests/', function (): void {
    // Same test-isolation rationale as Config::reset() above.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'StorageRegistry::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'StorageRegistry::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'StorageRegistry::reset('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

// P16: src/Piwigo/ is the typed source of truth for the 52 retired
// include/constants.php constants (AppInfo/AccessLevel/ActivitySystem/
// ValidationPattern/Tables/Config accessors) -- a regression guard, not a
// migration (this specific claim -- zero define() calls -- was already
// true before this phase; the 52-constant sweep confirmed it, this locks
// it in). Legacy include//admin/ root entry points are explicitly NOT
// scanned: PHPWG_ROOT_PATH keeps being define()'d there (its own literal
// './' value, deliberately unchanged -- see index.php's own docblock) for
// the not-yet-migrated call sites.
//
// A sibling "no PHPWG_ROOT_PATH read in src/Piwigo/" test was deliberately
// NOT added here despite the plan doc's "already true today" claim for it
// too -- that claim was verified WRONG: PHPWG_ROOT_PATH is read directly
// in ~50 real (non-comment) call sites across 12 src/Piwigo/ files
// (Admin/updates.php, Admin/plugins.php, Admin/languages.php,
// Admin/themes.php, Image/SrcImage.php, Image/DerivativeImage.php,
// Template/Template.php, Template/FileCombiner.php, Cache/
// PersistentFileCache.php), all pre-existing (P6-era), not introduced by
// P16. This is exactly the "885-usage bulk Paths migration across
// include//admin/" work already scoped to P17-23 by this phase's own
// plan -- adding an enforcement test for a claim that isn't true yet would
// need a ~50-entry exclusion list that only grows, defeating the point of
// a regression guard. Revisit once that migration actually lands.
//
// Comment-aware (unlike findCallSites() above, plain substring match):
// "define()" is common in explanatory prose (e.g. "the N define()
// constants this class replaces"), and demanding every present and future
// comment avoid that literal substring is far more fragile than just not
// scanning comment lines for this one check.

/**
 * @return list<array{path: string, line: int}>
 */
function findCallSitesOutsideComments(string $dir, string $needle): array
{
    $hits = [];
    if (! is_dir($dir)) {
        return $hits;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file RecursiveIteratorIterator loses this over RecursiveDirectoryIterator */
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        if ($source === false) {
            continue;
        }

        // Token-level scan: only real code can carry a call site. Comments,
        // string literals (e.g. InstallWizard's generated local/config/
        // database.inc.php template, whose define() lines execute in the
        // legacy bootstrap outside src/), heredoc bodies, and inline HTML
        // are blanked out (newlines preserved, so line numbers stay exact)
        // before the text search. Strictly more precise than the previous
        // comment-prefix line heuristic: every real call still matches.
        $blanked = '';
        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                $blanked .= $token;
                continue;
            }
            [$id, $text] = $token;
            if (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                $blanked .= preg_replace('/[^\n]/', ' ', $text);
                continue;
            }
            $blanked .= $text;
        }

        foreach (explode("\n", $blanked) as $lineNumber => $line) {
            if (str_contains($line, $needle)) {
                $hits[] = ['path' => $file->getPathname(), 'line' => $lineNumber + 1];
            }
        }
    }

    return $hits;
}

test('src/Piwigo/ contains no define() calls', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $hits = findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'define(');

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
            new ReflectionClass($fqcn)->getProperties(),
            static fn (ReflectionProperty $property): bool => ! $property->isReadOnly()
        );
        $violations = array_map(
            static fn (ReflectionProperty $property): string => "{$fqcn}::\${$property->getName()}",
            $mutableProperties
        );

        expect(array_values($violations))->toBe([]);
    }
});
