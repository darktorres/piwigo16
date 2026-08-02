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
// Bootstrap/ reach into the container before injection is possible; every
// root entry script (index.php included) reaches it only indirectly,
// through a Bootstrap/ class. docs/PLAN.md: "Gate: arch test
// enforcing this boundary from P7 onward, not deferred to P32."

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

test('Kernel::container() is only called from src/Piwigo/Bootstrap/', function (): void {
    // The root index.php clause this allowlist used to carry is gone:
    // index.php has been pure bootstrap + dispatch through
    // RequestBootstrap::bootEntryPoint() since P22, and never called
    // Kernel::container() directly itself -- every real container access
    // already goes through a Bootstrap/ class (Legacy Coupling Retirement
    // Phase 8, 8e).
    //
    // Singleton/service-locator elimination campaign: a handful of classes
    // outside Bootstrap/ also carry ONE narrow, explicitly-tracked
    // exception each -- a transitional `@deprecated ...Static()` shim
    // method (see e.g. Piwigo\Core\ApiKeyRequestFlag::isActiveStatic()'s
    // own docblock) that lets a not-yet-converted caller elsewhere keep
    // working unchanged until its own phase converts it. Every entry below
    // is removed once that class's shim is deleted -- this allow-list
    // should shrink back to empty by the end of the campaign, not grow
    // unbounded.
    $repoRoot = __DIR__ . '/../..';

    $shimAllowedFiles = [
        '/src/Piwigo/Core/ApiKeyRequestFlag.php',
        '/src/Piwigo/Core/InstallationFlag.php',
        '/src/Piwigo/Core/ProcessCache.php',
        '/src/Piwigo/Core/FilterState.php',
        '/src/Piwigo/Core/CurrentLogger.php',
        '/src/Piwigo/Section/SectionContextRegistry.php',
        '/src/Piwigo/Storage/StorageRegistry.php',
        '/src/Piwigo/Core/ErrorCollector.php',
        '/src/Piwigo/Core/RequestMountDepth.php',
        '/src/Piwigo/Core/WsContext.php',
        '/src/Piwigo/Core/AdminContext.php',
        '/src/Piwigo/Validation/InputValidator.php',
    ];

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'Kernel::container('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'Kernel::container('),
        ...findCallSitesInBinFiles($repoRoot, 'Kernel::container('),
    ];

    $disallowed = array_values(array_filter(
        $hits,
        static fn (array $hit): bool => ! str_contains($hit['path'], '/src/Piwigo/Bootstrap/')
            && ! array_any($shimAllowedFiles, static fn (string $allowed): bool => str_ends_with($hit['path'], $allowed))
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

test('CurrentConfig::reset() is only called from tests/', function (): void {
    // Mirrors the Kernel::reset() rule above -- reset() exists purely for
    // test isolation between cases; production code must never touch it.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'CurrentConfig::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'CurrentConfig::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'CurrentConfig::reset('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('SessionService::reset() is only called from tests/', function (): void {
    // Same test-isolation rationale as CurrentConfig::reset() above.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'SessionService::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'SessionService::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'SessionService::reset('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('StorageRegistry::disk() transitional shim has a shrinking, known allow-list', function (): void {
    // Singleton/service-locator elimination campaign, Phase 2: Piwigo\Ws\
    // PwgImages's chunked-upload assembly is the one remaining real caller
    // (the still-static Ws\Pwg* dispatch layer, Phase 10) not converted to
    // constructor injection, so it uses this static shim instead of the
    // real get() instance method (see that method's own docblock). Every
    // phase that converts one more of these files should remove it from
    // the allow-list below; once empty, delete the shim and this test.
    $repoRoot = __DIR__ . '/../..';

    $allowedFiles = [
        '/src/Piwigo/Ws/PwgImages.php',
    ];

    $hits = findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'StorageRegistry::disk(');

    $disallowed = array_values(array_filter(
        $hits,
        static fn (array $hit): bool => ! array_any($allowedFiles, static fn (string $allowed): bool => str_ends_with($hit['path'], $allowed))
    ));

    expect(describeCallSites($disallowed))->toBe([]);
});

test('CurrentConfigService::reset() is only called from tests/', function (): void {
    // Same test-isolation rationale as CurrentConfig::reset() above.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'CurrentConfigService::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'CurrentConfigService::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'CurrentConfigService::reset('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('DeploymentPolicy::set()/reset() are only called from tests/', function (): void {
    // Same test-isolation rationale as CurrentConfig::reset() above -- unlike
    // current()/load() (real production APIs), both exist purely so a test
    // can inject a specific policy or force re-resolution between cases.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'DeploymentPolicy::set('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'DeploymentPolicy::set('),
        ...findCallSitesInBinFiles($repoRoot, 'DeploymentPolicy::set('),
        ...findCallSites($repoRoot . '/src/Piwigo', 'DeploymentPolicy::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'DeploymentPolicy::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'DeploymentPolicy::reset('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

/**
 * P23 Stage 1f (finding #15, testable half): 24 more classes gained the
 * same "static reset() exists purely for test isolation between cases"
 * shape as the 7 above without ever getting their own arch test. Re-
 * verified directly (not trusting the plan's own stale "23 classes, no
 * exceptions" claim, which predates 2 of these classes entirely) --
 * DbCredentials::reset() turned out to have a real production caller
 * (Admin\Install\InstallWizard::performInstall(), reloading credentials
 * right after writing a fresh .env) and is deliberately excluded here,
 * its own docblock corrected instead of arch-tested. CurrentPaths::reset()
 * has exactly one real caller too, but a legitimate one: Kernel::reset()
 * itself cascades into it, and Kernel::reset() is already test-only-
 * verified above -- filtered out below by name rather than left
 * unguarded, so any *other* new direct caller still fails this test.
 */
test('AdminContext::isActiveStatic() transitional shim has a shrinking, known allow-list', function (): void {
    // Singleton/service-locator elimination campaign, Phase 3:
    // Piwigo\Page\PageHeaderRenderer and Piwigo\Bootstrap\RedirectService
    // both deliberately have no constructor at all (early-crash-fallback
    // shape, Phase 6), and Piwigo\Template\Template is still manually
    // `new`'d at dozens of call sites (Phase 6/8) -- all 3 use this static
    // shim instead of the real isActive() instance method (see that
    // method's own docblock). Every phase that converts one more of these
    // files should remove it from the allow-list below; once empty,
    // delete the shim and this test.
    $repoRoot = __DIR__ . '/../..';

    $allowedFiles = [
        '/src/Piwigo/Page/PageHeaderRenderer.php',
        '/src/Piwigo/Bootstrap/RedirectService.php',
        '/src/Piwigo/Template/Template.php',
    ];

    $hits = findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'AdminContext::isActiveStatic(');

    $disallowed = array_values(array_filter(
        $hits,
        static fn (array $hit): bool => ! array_any($allowedFiles, static fn (string $allowed): bool => str_ends_with($hit['path'], $allowed))
    ));

    expect(describeCallSites($disallowed))->toBe([]);
});

test('ApiKeyRequestFlag::isActiveStatic() transitional shim has a shrinking, known allow-list', function (): void {
    // Singleton/service-locator elimination campaign, Phase 1: real callers
    // (Piwigo\Ws\PwgCore/PwgServer -- Phase 10; Piwigo\Session\SessionService
    // -- Phase 4) aren't converted to constructor injection yet, so they use
    // this static shim instead of the real isActive() instance method (see
    // that method's own docblock). Every phase that converts one more of
    // these files should remove it from the allow-list below; once the
    // allow-list is empty, delete isActiveStatic() itself and this test.
    $repoRoot = __DIR__ . '/../..';

    $allowedFiles = [
        '/src/Piwigo/Ws/PwgCore.php',
        '/src/Piwigo/Ws/PwgServer.php',
        '/src/Piwigo/Session/SessionService.php',
    ];

    // Comment-aware (findCallSitesOutsideComments, not findCallSites): this
    // class's own docblock spells out the exact grep string callers should
    // use to find their own remaining references, which would otherwise
    // self-match as a false "call site" in its own file.
    $hits = findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'ApiKeyRequestFlag::isActiveStatic(');

    $disallowed = array_values(array_filter(
        $hits,
        static fn (array $hit): bool => ! array_any($allowedFiles, static fn (string $allowed): bool => str_ends_with($hit['path'], $allowed))
    ));

    expect(describeCallSites($disallowed))->toBe([]);
});

test('CurrentLogger::getStatic() transitional shim has a shrinking, known allow-list', function (): void {
    // Singleton/service-locator elimination campaign, Phase 2: real callers
    // (Piwigo\Core\UniqueExecLock -- genuinely static-only; Piwigo\Admin\
    // Upload\UploadService's 6 uploadFile* static event handlers;
    // Piwigo\Tag\TagService::setTagsOf(); Piwigo\Image\ImageService::
    // emptyLounge(); Piwigo\Ws\PwgUsers/Piwigo\Ws\PwgImages -- the
    // still-static Ws\Pwg* dispatch layer, Phase 10) aren't converted to
    // constructor injection, so they use this static shim instead of the
    // real get() instance method (see that method's own docblock). Every
    // phase that converts one more of these files should remove it from
    // the allow-list below; once empty, delete the shim and this test.
    $repoRoot = __DIR__ . '/../..';

    $allowedFiles = [
        '/src/Piwigo/Core/UniqueExecLock.php',
        '/src/Piwigo/Admin/Upload/UploadService.php',
        '/src/Piwigo/Tag/TagService.php',
        '/src/Piwigo/Image/ImageService.php',
        '/src/Piwigo/Ws/PwgUsers.php',
        '/src/Piwigo/Ws/PwgImages.php',
    ];

    $hits = findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'CurrentLogger::getStatic(');

    $disallowed = array_values(array_filter(
        $hits,
        static fn (array $hit): bool => ! array_any($allowedFiles, static fn (string $allowed): bool => str_ends_with($hit['path'], $allowed))
    ));

    expect(describeCallSites($disallowed))->toBe([]);
});

test('CurrentPaths::reset() is only called from tests/ or the Kernel::reset() cascade', function (): void {
    // Kernel::reset() (already verified test-only above) cascades into
    // CurrentPaths::reset() itself -- the one legitimate non-tests/ call
    // site, filtered out by path so any *other* direct caller still fails.
    $repoRoot = __DIR__ . '/../..';

    $hits = array_values(array_filter(
        [
            ...findCallSites($repoRoot . '/src/Piwigo', 'CurrentPaths::reset('),
            ...findCallSitesInRootPhpFiles($repoRoot, 'CurrentPaths::reset('),
            ...findCallSitesInBinFiles($repoRoot, 'CurrentPaths::reset('),
        ],
        static fn (array $hit): bool => ! str_ends_with($hit['path'], '/Core/Kernel.php')
    ));

    expect(describeCallSites($hits))->toBe([]);
});

test('ErrorCollector::recordFatalStatic() transitional shim has a shrinking, known allow-list', function (): void {
    // Singleton/service-locator elimination campaign, Phase 2: Piwigo\
    // Template\Template and Piwigo\Html\HtmlService are both still
    // manually `new`'d at dozens of call sites each (Phase 6, not yet
    // converted), so neither can take ErrorCollector via constructor
    // injection yet -- they use this static shim instead of the real
    // recordFatal() instance method (see that method's own docblock).
    // Every phase that converts one more of these files should remove it
    // from the allow-list below; once empty, delete the shim and this test.
    $repoRoot = __DIR__ . '/../..';

    $allowedFiles = [
        '/src/Piwigo/Template/Template.php',
        '/src/Piwigo/Html/HtmlService.php',
    ];

    $hits = findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'ErrorCollector::recordFatalStatic(');

    $disallowed = array_values(array_filter(
        $hits,
        static fn (array $hit): bool => ! array_any($allowedFiles, static fn (string $allowed): bool => str_ends_with($hit['path'], $allowed))
    ));

    expect(describeCallSites($disallowed))->toBe([]);
});

test('FilterState::*Static() transitional shims have a shrinking, known allow-list', function (): void {
    // Singleton/service-locator elimination campaign, Phase 2: the real
    // writer (Piwigo\Filter\FilterService/Piwigo\Bootstrap\RequestBootstrap)
    // and most readers (SectionPopulator, Category\CategoryService,
    // Menu\MenubarRenderer, Controller\PictureController, and every
    // controller that calls MenubarRenderer::render()) take FilterState via
    // constructor/explicit-parameter injection. Piwigo\Permission\
    // PermissionService::getSqlConditionFandFAsCondition() is the one
    // exception: it has ~30 real callers, several inside the still-static
    // Ws\Pwg* dispatch layer (Phase 10), so it uses these static shims
    // instead of the real isInitialized()/visibleCategories()/
    // visibleImages() instance methods (see isInitializedStatic()'s own
    // docblock). Delete the shims and this test once PermissionService
    // itself takes FilterState via constructor injection.
    $repoRoot = __DIR__ . '/../..';

    $allowedFiles = [
        '/src/Piwigo/Permission/PermissionService.php',
    ];

    $hits = [
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'FilterState::isInitializedStatic('),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'FilterState::visibleCategoriesStatic('),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'FilterState::visibleImagesStatic('),
    ];

    $disallowed = array_values(array_filter(
        $hits,
        static fn (array $hit): bool => ! array_any($allowedFiles, static fn (string $allowed): bool => str_ends_with($hit['path'], $allowed))
    ));

    expect(describeCallSites($disallowed))->toBe([]);
});

test('InstallationFlag::isActiveStatic() transitional shim has a shrinking, known allow-list', function (): void {
    // Singleton/service-locator elimination campaign, Phase 1: real callers
    // (Piwigo\Core\Lang -- Phase 8; Piwigo\Users\UserService -- large
    // construction-site fan-out, out of scope for this phase;
    // Piwigo\Bootstrap\SessionBootstrap -- a genuinely static-only class)
    // aren't converted to constructor injection yet, so they use this
    // static shim instead of the real isActive() instance method (see
    // that method's own docblock). Every phase that converts one more of
    // these files should remove it from the allow-list below; once the
    // allow-list is empty, delete isActiveStatic() itself and this test.
    $repoRoot = __DIR__ . '/../..';

    $allowedFiles = [
        '/src/Piwigo/Core/Lang.php',
        '/src/Piwigo/Users/UserService.php',
        '/src/Piwigo/Bootstrap/SessionBootstrap.php',
    ];

    $hits = findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'InstallationFlag::isActiveStatic(');

    $disallowed = array_values(array_filter(
        $hits,
        static fn (array $hit): bool => ! array_any($allowedFiles, static fn (string $allowed): bool => str_ends_with($hit['path'], $allowed))
    ));

    expect(describeCallSites($disallowed))->toBe([]);
});

test('Lang::reset() is only called from tests/', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'Lang::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'Lang::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'Lang::reset('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('PageState::reset() is only called from tests/', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'PageState::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'PageState::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'PageState::reset('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('ProcessCache::*Static() transitional shims have a shrinking, known allow-list', function (): void {
    // Singleton/service-locator elimination campaign, Phase 1: real callers
    // (Piwigo\Html\HtmlService -- 444 manual construction sites, unrelated
    // cleanup out of scope here; Piwigo\Template\Template -- constructed
    // with runtime-computed args, never autowireable as-is; Piwigo\Users\
    // UserService -- Phase 4/8 territory; Piwigo\Core\RecentIconResolver --
    // a genuinely static-only utility) aren't converted to constructor
    // injection, so they use these static shims instead of the real
    // has()/get()/set() instance methods (see hasStatic()'s own docblock).
    // Every phase that converts one more of these files should remove it
    // from the allow-list below; once empty, delete all 4 shims and this
    // test.
    $repoRoot = __DIR__ . '/../..';

    $allowedFiles = [
        '/src/Piwigo/Html/HtmlService.php',
        '/src/Piwigo/Template/Template.php',
        '/src/Piwigo/Users/UserService.php',
        '/src/Piwigo/Core/RecentIconResolver.php',
    ];

    $hits = [
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'ProcessCache::hasStatic('),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'ProcessCache::getStatic('),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'ProcessCache::setStatic('),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'ProcessCache::forgetStatic('),
    ];

    $disallowed = array_values(array_filter(
        $hits,
        static fn (array $hit): bool => ! array_any($allowedFiles, static fn (string $allowed): bool => str_ends_with($hit['path'], $allowed))
    ));

    expect(describeCallSites($disallowed))->toBe([]);
});

test('RequestMountDepth::currentStatic() transitional shim has a shrinking, known allow-list', function (): void {
    // Singleton/service-locator elimination campaign, Phase 3:
    // Piwigo\Url\UrlService and Piwigo\Auth\CookieService are both still
    // manually `new`'d at dozens of call sites each (Phase 6), so neither
    // can take RequestMountDepth via constructor injection yet -- they
    // use this static shim instead of the real current() instance method
    // (see that method's own docblock). Every phase that converts one
    // more of these files should remove it from the allow-list below;
    // once empty, delete the shim and this test.
    $repoRoot = __DIR__ . '/../..';

    $allowedFiles = [
        '/src/Piwigo/Url/UrlService.php',
        '/src/Piwigo/Auth/CookieService.php',
    ];

    $hits = findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'RequestMountDepth::currentStatic(');

    $disallowed = array_values(array_filter(
        $hits,
        static fn (array $hit): bool => ! array_any($allowedFiles, static fn (string $allowed): bool => str_ends_with($hit['path'], $allowed))
    ));

    expect(describeCallSites($disallowed))->toBe([]);
});

test('ServerTiming::reset() is only called from tests/', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'ServerTiming::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'ServerTiming::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'ServerTiming::reset('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('WsContext::isActiveStatic() transitional shim has a shrinking, known allow-list', function (): void {
    // Singleton/service-locator elimination campaign, Phase 3:
    // Piwigo\Admin\PluginMaintain (a base class extended by every
    // third-party plugin's own maintain class -- its constructor
    // signature isn't this campaign's to change), Piwigo\Url\UrlService
    // (Phase 6), and Piwigo\Admin\Upload\UploadService::addUploadedFile()
    // (reachable from the still-static Ws\PwgImages dispatch layer, Phase
    // 10) all use this static shim instead of the real isActive() instance
    // method (see that method's own docblock). Every phase that converts
    // one more of these files should remove it from the allow-list below;
    // once empty, delete the shim and this test.
    $repoRoot = __DIR__ . '/../..';

    $allowedFiles = [
        '/src/Piwigo/Admin/PluginMaintain.php',
        '/src/Piwigo/Url/UrlService.php',
        '/src/Piwigo/Admin/Upload/UploadService.php',
    ];

    $hits = findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'WsContext::isActiveStatic(');

    $disallowed = array_values(array_filter(
        $hits,
        static fn (array $hit): bool => ! array_any($allowedFiles, static fn (string $allowed): bool => str_ends_with($hit['path'], $allowed))
    ));

    expect(describeCallSites($disallowed))->toBe([]);
});

test('InputValidator::createStatic() transitional shim has a shrinking, known allow-list', function (): void {
    // Singleton/service-locator elimination campaign, Phase 3: every one
    // of InputValidator's real construction sites turned out to be a
    // `Request\*Request::fromGlobals()` static factory (P27/SEC-40, ~40
    // files) or another still-static caller (Piwigo\Admin\AdminShell,
    // Piwigo\Controller\Admin\{BatchManagerSubController,
    // ConfigurationSubController,NotificationByMailSubController},
    // Piwigo\Ws\PwgCore) -- none has an instance context to receive
    // constructor injection through, so this static shim (see
    // createStatic()'s own docblock) is genuinely this class's *primary*
    // entry point today, not a rare straggler. This allow-list is
    // unusually large and won't shrink until Request DTOs' own
    // `fromGlobals()` static-factory pattern is itself reworked -- a
    // separate, not-yet-planned initiative, not part of this campaign.
    $repoRoot = __DIR__ . '/../..';

    $allowedFiles = [
        '/src/Piwigo/Admin/AdminShell.php',
        '/src/Piwigo/Admin/Install/Request/InstallWizardRequest.php',
        '/src/Piwigo/Admin/Maintenance/Request/DerivativesTypeRequest.php',
        '/src/Piwigo/Admin/Request/AdminShellRequest.php',
        '/src/Piwigo/Admin/Request/AlbumNotificationSubmitRequest.php',
        '/src/Piwigo/Admin/Request/AlbumsRequest.php',
        '/src/Piwigo/Admin/Request/BatchManagerGlobalRequest.php',
        '/src/Piwigo/Admin/Request/BatchManagerUnitRequest.php',
        '/src/Piwigo/Admin/Request/CatListRequest.php',
        '/src/Piwigo/Admin/Request/CatOptionsRequest.php',
        '/src/Piwigo/Admin/Request/GroupPermSubmitRequest.php',
        '/src/Piwigo/Admin/Request/HistoryFilterRequest.php',
        '/src/Piwigo/Admin/Request/LanguagesInstalledActionRequest.php',
        '/src/Piwigo/Admin/Request/PhotosAddDirectRequest.php',
        '/src/Piwigo/Admin/Request/PictureCoiRequest.php',
        '/src/Piwigo/Admin/Request/PictureFormatsImageIdRequest.php',
        '/src/Piwigo/Admin/Request/PictureModifyRequest.php',
        '/src/Piwigo/Admin/Request/RatingRequest.php',
        '/src/Piwigo/Admin/Request/UpdatesPwgRequest.php',
        '/src/Piwigo/Admin/Request/UserActivityRequest.php',
        '/src/Piwigo/Admin/Request/UserListFilterRequest.php',
        '/src/Piwigo/Admin/Request/UserPermSubmitRequest.php',
        '/src/Piwigo/Controller/Admin/BatchManagerSubController.php',
        '/src/Piwigo/Controller/Admin/ConfigurationSubController.php',
        '/src/Piwigo/Controller/Admin/NotificationByMailSubController.php',
        '/src/Piwigo/Controller/Admin/Request/BatchManagerRequest.php',
        '/src/Piwigo/Controller/Admin/Request/ConfigurationRequest.php',
        '/src/Piwigo/Controller/Admin/Request/ExtensionTabRequest.php',
        '/src/Piwigo/Controller/Admin/Request/MaintenanceDispatchRequest.php',
        '/src/Piwigo/Controller/Admin/Request/NotificationByMailRequest.php',
        '/src/Piwigo/Controller/Admin/Request/PermalinksRequest.php',
        '/src/Piwigo/Controller/Admin/Request/PhotoDispatchRequest.php',
        '/src/Piwigo/Controller/Admin/Request/PluginSectionRequest.php',
        '/src/Piwigo/Controller/Admin/Request/SiteUpdateRequest.php',
        '/src/Piwigo/Controller/Admin/Request/ThemeIdRequest.php',
        '/src/Piwigo/Controller/Admin/Request/UpdatesTabRequest.php',
        '/src/Piwigo/Controller/Request/ActionRequest.php',
        '/src/Piwigo/Controller/Request/CommentsRequest.php',
        '/src/Piwigo/Controller/Request/FeedRequest.php',
        '/src/Piwigo/Controller/Request/IdentificationSubmitRequest.php',
        '/src/Piwigo/Controller/Request/PasswordRequest.php',
        '/src/Piwigo/Controller/Request/PictureRequest.php',
        '/src/Piwigo/Controller/Request/SearchQueryRequest.php',
        '/src/Piwigo/Ws/PwgCore.php',
    ];

    $hits = findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'InputValidator::createStatic(');

    $disallowed = array_values(array_filter(
        $hits,
        static fn (array $hit): bool => ! array_any($allowedFiles, static fn (string $allowed): bool => str_ends_with($hit['path'], $allowed))
    ));

    expect(describeCallSites($disallowed))->toBe([]);
});

test('Translator::reset() is only called from tests/', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'Translator::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'Translator::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'Translator::reset('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('MailService::reset() is only called from tests/', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'MailService::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'MailService::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'MailService::reset('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('EventDispatcher::reset() is only called from tests/', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'EventDispatcher::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'EventDispatcher::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'EventDispatcher::reset('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('SectionContextRegistry::currentStatic() transitional shim has a shrinking, known allow-list', function (): void {
    // Singleton/service-locator elimination campaign, Phase 2: real
    // readers/the writer (SectionPopulator, GalleryController,
    // PictureController, Menu\MenubarRenderer::render() + its 11
    // controller callers) take it via constructor/explicit-parameter
    // injection. Piwigo\Url\UrlService is the one exception: it's one of
    // the ~440 manually-`new`'d call sites Phase 6 exists to fix, so it
    // uses this static shim instead of the real current() instance method
    // (see that method's own docblock). Delete once UrlService itself
    // takes SectionContextRegistry via constructor injection (Phase 6).
    $repoRoot = __DIR__ . '/../..';

    $allowedFiles = [
        '/src/Piwigo/Url/UrlService.php',
    ];

    $hits = findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'SectionContextRegistry::currentStatic(');

    $disallowed = array_values(array_filter(
        $hits,
        static fn (array $hit): bool => ! array_any($allowedFiles, static fn (string $allowed): bool => str_ends_with($hit['path'], $allowed))
    ));

    expect(describeCallSites($disallowed))->toBe([]);
});

test('CurrentTemplate::reset() is only called from tests/', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'CurrentTemplate::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'CurrentTemplate::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'CurrentTemplate::reset('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('RootPathOverride::reset() is only called from tests/', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'RootPathOverride::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'RootPathOverride::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'RootPathOverride::reset('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('CurrentUser::reset() is only called from tests/', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'CurrentUser::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'CurrentUser::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'CurrentUser::reset('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

// P16: src/Piwigo/ is the typed source of truth for the 52 retired
// include/constants.php constants (AppInfo/AccessLevel/ActivitySystem/
// ValidationPattern/Tables/Config accessors) -- a regression guard, not a
// migration (this specific claim -- zero define() calls -- was already
// true before this phase; the 52-constant sweep confirmed it, this locks
// it in). Legacy include//admin/ root entry points are explicitly NOT
// scanned here (this test only covers src/Piwigo/): PHPWG_ROOT_PATH used
// to keep being define()'d in i.php for its own not-yet-migrated call
// sites, fully retired now (Workstream C3 Part III) -- see the sibling
// "no PHPWG_ROOT_PATH/PWG_LOCAL_DIR reads" test below, zero-tolerance.
//
// The sibling "no PHPWG_ROOT_PATH/PWG_LOCAL_DIR read in src/Piwigo/" test
// this comment used to defer (P16: ~50 real call sites across 12 files,
// "revisit once that migration actually lands") is below, now that the
// migration has actually landed (Legacy Coupling Retirement gap-closure,
// entry-shell define()/include round: both constants replaced by
// Piwigo\Core\Paths/CurrentPaths -- DI-constructed classes get Paths
// threaded through their constructor, everything else reads
// CurrentPaths::get()). Controller/ImageDerivativeController.php's own 2
// sites (genuine URL generation, not filesystem paths) were the one
// deliberately deferred exception, closed by Workstream C3 Part III
// (i.php joining the real routing pipeline): UrlService::
// getAbsoluteRootUrl(false) replaces them, the same request-depth-
// independent mechanism (cookiePath(), SCRIPT_NAME/REDIRECT_URL-based)
// this codebase already uses elsewhere -- zero-tolerance now, matching the
// IN_ADMIN/IN_WS/... test below.
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

test('src/Piwigo/ contains no PHPWG_ROOT_PATH/PWG_LOCAL_DIR reads', function (): void {
    // Legacy Coupling Retirement gap-closure (entry-shell define()/include
    // round) + Workstream C3 Part III: both constants are fully retired,
    // zero-tolerance -- Controller/ImageDerivativeController.php's own last
    // 2 sites (see this file's own docblock a few lines above) are gone
    // too. A hit anywhere means a new raw read was introduced and must be
    // migrated onto Paths/CurrentPaths (or UrlService::
    // getAbsoluteRootUrl(false) for URL generation) instead of allowlisted.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'PHPWG_ROOT_PATH'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'PWG_LOCAL_DIR'),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('src/Piwigo/ reads $_POST/$_GET/$_REQUEST/$_FILES only inside a Request DTO or a documented exception', function (): void {
    // P27/SEC-40 completion: every page controller/WS method/domain
    // service that used to read $_GET/$_POST/$_REQUEST/$_FILES directly
    // now does so through a validating `{Module}/Request/{Name}` DTO's
    // own `fromGlobals()` (the sole legitimate raw read a DTO class makes
    // for itself -- this scan only covers files OUTSIDE any `Request/`
    // directory, matching `RequestFactory::fromGlobals()`'s own
    // established "the wrapper's internals are exempt from the rule it
    // enforces on everyone else" shape). A hit anywhere else means a new
    // raw read was introduced and must be migrated onto a Request DTO,
    // not allowlisted -- the 6 files below are the only real exceptions
    // that survived a full repo audit, each already documented at its own
    // call site:
    //   - Admin/AdminShell.php: runDispatch()'s own page-slug alias
    //     rewriting ($_GET['page']/['section']/['tab']) -- load-bearing,
    //     must survive into RequestFactory::fromGlobals()'s own later
    //     read in the same method.
    //   - Bootstrap/UserBootstrap.php: the api_key pwg_token synthesis
    //     ($_POST['pwg_token'] = $_GET['pwg_token'] = ...) -- load-
    //     bearing, must survive into Ws\Request\WsRawRequest's own later
    //     read in the same request.
    //   - Bootstrap/RequestBootstrap.php: the once-per-request magic-
    //     quotes-style superglobal sanitization pass (array_walk_recursive
    //     over the whole $_GET/$_POST), which necessarily runs before any
    //     Request DTO in the app could read either array -- the same
    //     "earliest possible bootstrap stage" category as
    //     RequestFactory::fromGlobals() itself.
    //   - Ws/PwgServer.php: isPost()'s own `$_POST !== []` -- a minimal
    //     single-fact reader (matches this same file's own docblock),
    //     not a bag of request data a DTO wrapper would help.
    //   - Ws/PwgImages.php: upload()'s own `$_FILES !== []` top-level
    //     existence check -- governs a broader condition ("was ANY file
    //     posted") than the 'file' key specifically, which
    //     Ws\Request\UploadedFileRequest already covers.
    //   - Admin/BatchManagerGlobalPageRenderer.php: the pre-DTO CSRF gate
    //     (`count($_POST) > 0`), which must run before
    //     Admin\Request\BatchManagerGlobalRequest::fromGlobals() to match
    //     the original's own CSRF-before-field-validation ordering.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', '$_POST'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', '$_GET'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', '$_REQUEST'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', '$_FILES'),
    ];

    $allowlistedSuffixes = [
        'Admin/AdminShell.php',
        'Bootstrap/UserBootstrap.php',
        'Bootstrap/RequestBootstrap.php',
        'Ws/PwgServer.php',
        'Ws/PwgImages.php',
        'Admin/BatchManagerGlobalPageRenderer.php',
    ];
    $unexpected = array_values(array_filter(
        $hits,
        static fn (array $hit): bool => ! str_contains($hit['path'], '/Request/')
            && array_all($allowlistedSuffixes, static fn (string $suffix): bool => ! str_ends_with($hit['path'], $suffix))
    ));

    expect(describeCallSites($unexpected))->toBe([]);
});

test('src/Piwigo/ contains no raw IN_ADMIN/IN_WS/PHPWG_INSTALLED/PHPWG_URL/PHPWG_DOMAIN/PEM_URL reads', function (): void {
    // Legacy Coupling Retirement gap-closure (entry-shell define()/include
    // round, Part 0b): all 6 are fully retired, zero-tolerance -- typed
    // replacements (Piwigo\Core\AdminContext/WsContext/InstallationFlag/
    // AppInfo::DOMAIN/AppInfo::URL/Bootstrap\RequestBootstrap::pemUrl())
    // cover every real caller, so unlike PHPWG_ROOT_PATH/PWG_LOCAL_DIR
    // above there's no legitimate exception left to allowlist. The 2
    // entry-shell define()s that still legitimately exist (admin.php/
    // admin/popuphelp.php call AdminContext::mark(), not define()) and
    // install.php's own `define('PHPWG_INSTALLED', true)` (right next to
    // its own PWG_CHARSET/DB_CHARSET/DB_COLLATE `defined(...) or
    // define(...)` guards) are outside src/Piwigo/, so this scan never
    // sees them.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'IN_ADMIN'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'IN_WS'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'PHPWG_INSTALLED'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'PHPWG_URL'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'PHPWG_DOMAIN'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'PEM_URL'),
    ];

    // Piwigo\Core\InstallationFlag::isActive() itself legitimately reads
    // `defined('PHPWG_INSTALLED')` -- it IS the typed replacement, not a
    // caller of it (this same style of test elsewhere in this file
    // doesn't ban PWG_CHARSET/DB_CHARSET's own identical self-reads
    // either, since there's no sibling "no PWG_CHARSET" test at all).
    $unexpected = array_values(array_filter(
        $hits,
        static fn (array $hit): bool => ! str_ends_with($hit['path'], 'Core/InstallationFlag.php')
    ));

    expect(describeCallSites($unexpected))->toBe([]);
});

test('src/Piwigo/ contains no global $filter/$pwg_loaded_plugins/$template/$page declarations', function (): void {
    // Phase 2 global-residual sweep (2026-07-19): all 4 clusters were
    // fully retired, not just reduced -- $filter/$pwg_loaded_plugins onto
    // new Piwigo\Core\FilterState/Piwigo\Admin\LoadedPlugins singletons,
    // $template onto the already-existing Piwigo\Template\CurrentTemplate,
    // $page either deleted as confirmed-dead code or converted to a
    // plain per-method local (matching Section\SectionPopulator's own
    // earlier Track A5.2e precedent) or a private static guard
    // (Admin\Maintenance\FilesystemIntegrityChecker::fsQuickCheck()).
    // Zero-tolerance, no allowlist needed -- matches the "no define()
    // calls" precedent above, not countExitCallsPerFile()'s allowlisted
    // shape, since nothing here turned out to be a legitimate permanent
    // bridge.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'global $filter'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'global $pwg_loaded_plugins'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'global $template'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'global $page'),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('src/Piwigo/ contains no global $conf/$prefixeTable/$last_time/$t2 declarations', function (): void {
    // Legacy Coupling Retirement "fix all" gap-closure (2026-07-20): the
    // 36 remaining global statements across the 151 frozen DbPatch/
    // VersionUpgrade migration files (plus InstallWizard's constructor and
    // RequestBootstrap::configure()'s $t2) are retired -- Tables::/
    // DbCredentials::current()->prefix for the table-prefix reads, and, for the handful
    // of keys genuinely only ever set by a site's own
    // local/config/config.inc.php (never mirrored into CurrentConfig:: mid-
    // migration), LegacyFileConf::read()/LegacyDbLayer::value() (both in
    // the now-deleted DbPatch namespace at the time; LegacyFileConf moved
    // to Piwigo\Admin\Install\ directly, LegacyDbLayer's own last caller
    // was deleted along with it -- gap-closure Stage 1a-bis). $t2
    // (RequestBootstrap::configure()) is now an explicit parameter
    // instead, passed straight through from include/common.inc.php's own
    // capture. Zero-tolerance, no allowlist needed -- same shape as the
    // test above.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'global $conf'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'global $prefixeTable'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'global $last_time'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'global $t2'),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

test('src/Piwigo/ contains no bare add_event_handler()/trigger_change()/trigger_notify() calls', function (): void {
    // Phase 3 event dispatch retarget sweep (2026-07-19): the free-function
    // bridge (src/Piwigo/PluginConfig/functions.php, a pure 1-line
    // delegate to EventDispatcher::get()) is deleted -- all 241 real call
    // sites across 84 files now call
    // Piwigo\PluginConfig\EventDispatcher::get()->
    // {addEventHandler,triggerChange,triggerNotify}() directly. Needed a
    // deptrac.yaml layer split first (EventDispatcher moved
    // L2bExtendedDomain -> L1Infrastructure, split from its namespace-mate
    // PluginRepository) since 14 real callers live in L1Infrastructure/
    // L2aCoreDomain, which the untracked free-function form had been
    // hiding from deptrac. Zero-tolerance, no allowlist needed -- same
    // shape as the test above.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'add_event_handler('),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'trigger_change('),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'trigger_notify('),
    ];

    expect(describeCallSites($hits))->toBe([]);
});

/**
 * Track B typed-event-object gap closure's own door-lock. Neither
 * existing helper fits: findCallSitesOutsideComments() blanks every
 * T_CONSTANT_ENCAPSED_STRING token -- including its surrounding quote
 * characters -- before searching, so a needle containing a literal `'`
 * would never match anything; countExitCallsPerFile() allowlists by
 * per-file *count*, not by which event name a call site names, so it
 * can't distinguish an allowlisted WS call from a missed conversion
 * sharing the same file. Token-aware instead: walk tokens for a
 * T_STRING matching one of the three legacy dispatch method names,
 * preceded by T_OBJECT_OPERATOR (`->`), then inspect its first argument
 * token. A converted call site's first argument is `SomeEvent::class`
 * -- a T_STRING/T_CLASS pair after T_DOUBLE_COLON, an entirely different
 * token shape -- and never matches the T_CONSTANT_ENCAPSED_STRING check
 * below, so it's never flagged regardless of the class name chosen.
 *
 * @param list<string> $allowlist
 * @return list<array{path: string, line: int}>
 */
function findStringKeyedDispatchCallSites(string $dir, array $allowlist): array
{
    $hits = [];
    if (! is_dir($dir)) {
        return $hits;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    $methodNames = ['addEventHandler', 'triggerChange', 'triggerNotify'];

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file RecursiveIteratorIterator loses this over RecursiveDirectoryIterator */
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        if ($source === false) {
            continue;
        }

        $hit = false;
        foreach ($methodNames as $name) {
            if (str_contains($source, $name)) {
                $hit = true;
                break;
            }
        }
        if (! $hit) {
            continue;
        }

        $tokens = token_get_all($source);
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (! is_array($tok) || $tok[0] !== T_STRING || ! in_array($tok[1], $methodNames, true)) {
                continue;
            }

            $j = $i - 1;
            while ($j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j--;
            }
            $prev = $j >= 0 ? $tokens[$j] : null;
            if (! (is_array($prev) && $prev[0] === T_OBJECT_OPERATOR)) {
                continue;
            }

            $k = $i + 1;
            while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                $k++;
            }
            if (! ($k < $n && is_string($tokens[$k]) && $tokens[$k] === '(')) {
                continue;
            }

            $m = $k + 1;
            while ($m < $n && is_array($tokens[$m]) && in_array($tokens[$m][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $m++;
            }
            $arg = $m < $n ? $tokens[$m] : null;
            if (! (is_array($arg) && $arg[0] === T_CONSTANT_ENCAPSED_STRING)) {
                continue; // SomeEvent::class or any other non-string-literal shape -- already converted
            }

            $literal = stripcslashes(substr($arg[1], 1, -1));
            if (in_array($literal, $allowlist, true)) {
                continue;
            }

            $hits[] = ['path' => $file->getPathname(), 'line' => $tok[2]];
        }
    }

    return $hits;
}

test('src/Piwigo/ contains no string-keyed EventDispatcher dispatch calls outside the meta allowlist', function (): void {
    // Track B typed-event-object gap closure (12 batches, landed
    // 2026-08-02): all 155 real legacy events now dispatch through typed
    // SomeEvent::class objects via addTypedHandler()/dispatchChange()/
    // dispatchNotify() -- including the 7 WS-protocol-lifecycle events
    // (get_history, ws_users_getList, ws_invoke_allowed, ws_add_methods,
    // ws_images_uploadCompleted, sendResponse, merge_tags) originally
    // deferred behind P26 (WS API removal), converted ahead of that on
    // explicit direction rather than waiting.
    //
    // 'trigger' is the one permanent exception: EventDispatcher's own
    // internal meta-notification channel (its dispatchChange()/
    // dispatchNotify()/triggerChange()/triggerNotify() all self-notify via
    // $this->triggerNotify('trigger', ...)), never a batch event, stays
    // string-keyed permanently. This is the only reason EventDispatcher.php
    // itself has any hits to allowlist -- every other real call site in
    // src/Piwigo/ converted.
    $repoRoot = __DIR__ . '/../..';

    $allowlist = ['trigger'];

    $hits = findStringKeyedDispatchCallSites($repoRoot . '/src/Piwigo', $allowlist);

    expect(describeCallSites($hits))->toBe([]);
});

/**
 * Token-aware, unlike findCallSitesOutsideComments()'s plain (post-
 * blanking) substring match: several of Track C's retired free-function
 * names collide as a bare substring with a real, still-legitimate OOP
 * method call of the identical short name -- most visibly `redirect(`,
 * which matches both the retired bare `redirect()` free function AND
 * every real `$this->redirectService->redirect(...)` call this same
 * phase (4b) introduced. Reuses the exact "real bare call" token logic
 * (T_STRING name match, not preceded by ->/::/function/backslash,
 * immediately followed by `(`) this phase's own scan_url_calls.php/
 * scan_l10n_calls.php scripts used throughout Phase 4c/4d to find every
 * real caller in the first place -- same check, now locked in as a
 * permanent regression guard.
 *
 * @param list<string> $names
 * @return list<array{path: string, line: int}>
 */
function findBareCallSites(string $dir, array $names): array
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

        $hit = false;
        foreach ($names as $name) {
            if (str_contains($source, $name)) {
                $hit = true;
                break;
            }
        }
        if (! $hit) {
            continue;
        }

        $tokens = token_get_all($source);
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (! is_array($tok) || $tok[0] !== T_STRING || ! in_array($tok[1], $names, true)) {
                continue;
            }
            $j = $i - 1;
            while ($j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j--;
            }
            $prev = $j >= 0 ? $tokens[$j] : null;
            if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_STRING], true)) {
                continue;
            }
            if (is_string($prev) && $prev === '\\') {
                continue;
            }
            $k = $i + 1;
            while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                $k++;
            }
            $next = $k < $n ? $tokens[$k] : null;
            if (! (is_string($next) && $next === '(')) {
                continue;
            }
            $hits[] = ['path' => $file->getPathname(), 'line' => $tok[2]];
        }
    }

    return $hits;
}

test('src/Piwigo/ contains no bare calls to any Track C retired free function', function (): void {
    // Legacy Coupling Retirement Track C (Phases 4a-4d, 2026-07-19/20):
    // retires the last 4 composer.json autoload.files free-function
    // bridges -- Category/functions.php (7 remaining functions),
    // Http/functions.php (3), Url/functions.php (17 -- parse_section_url
    // was already retired in an earlier session, ahead of this list),
    // Lang/functions.php (2) -- all 4 files deleted, all their real call
    // sites (1,349 across 151 files, per the original planning sweep)
    // retargeted onto real class methods. Zero-tolerance, no allowlist
    // needed -- same shape as the P16/Phase 3 tests above, using
    // findBareCallSites() instead of findCallSitesOutsideComments() since
    // several of these names (most notably `redirect`) collide as a bare
    // substring with a real, still-legitimate method call of the same
    // short name.
    $repoRoot = __DIR__ . '/../..';

    $retiredFunctionNames = [
        // Phase 4a -- Category/functions.php
        'get_subcat_ids', 'get_cat_info', 'get_uppercat_ids', 'set_cat_visible',
        'set_cat_status', 'set_random_representant', 'create_virtual_category',
        // Phase 4b -- Http/functions.php
        'redirect', 'redirect_html', 'redirect_http',
        // Phase 4c -- Url/functions.php
        'get_root_url', 'get_absolute_root_url', 'add_url_params', 'make_index_url',
        'duplicate_index_url', 'duplicate_picture_url', 'make_picture_url',
        'parse_section_url', 'parse_well_known_params_url', 'get_action_url',
        'get_element_url', 'set_make_full_url', 'unset_make_full_url', 'embellish_url',
        'get_gallery_home_url', 'get_query_string_diff', 'url_is_remote', 'get_user_favorites',
        // Phase 4d -- Lang/functions.php
        'l10n', 'l10n_dec',
    ];

    $hits = findBareCallSites($repoRoot . '/src/Piwigo', $retiredFunctionNames);

    expect(describeCallSites($hits))->toBe([]);
});

/**
 * die() and exit() -- with or without parens/arguments -- both tokenize
 * as T_EXIT, so this catches every real call site (unlike a substring
 * search, immune to a false match on an identifier merely containing
 * "exit", and unlike findCallSitesOutsideComments() above, doesn't need
 * a needle at all).
 *
 * @return array<string, int> relative path (from $dir itself) => call count
 */
function countExitCallsPerFile(string $dir): array
{
    $counts = [];
    if (! is_dir($dir)) {
        return $counts;
    }
    $base = $dir . '/';
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

        $count = 0;
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_EXIT) {
                $count++;
            }
        }

        if ($count > 0) {
            $relative = substr($file->getPathname(), strlen($base));
            $counts[$relative] = $count;
        }
    }

    ksort($counts);

    return $counts;
}

test('src/Piwigo/ contains no die()/exit() calls outside the documented allowlist', function (): void {
    // Phase 1k close-out (2026-07-19): every site below was read in full
    // and confirmed intentional -- grouped by rationale, not alphabetical.
    // A new file calling die()/exit(), or a changed count in a file
    // already listed here, fails this test until reviewed and reflected
    // here explicitly. This is a count-based allowlist, not a line-based
    // one: line numbers drift with unrelated edits, call-site counts
    // don't (a genuinely new call site always changes the count).
    $allowlist = [
        // Ws/* raw-response mechanism: the whole Ws/ module sends its own
        // JSON-RPC/XML/PHP-serialized response and exits, by design --
        // bypasses the PSR-7 middleware pipeline entirely (WsController's
        // own docblock: "PwgServer::run() always ends the response itself
        // ... there is no real PSR-7 Response to construct"). Same
        // mechanism reached from Bootstrap/UserBootstrap.php's api_key
        // gate and Admin/Upload/UploadService.php's IN_WS branch.
        // Ws/PwgImages.php's own former 5 raw die('{"jsonrpc"...}') upload-
        // error sites (Legacy Coupling Retirement gap-closure, Workstream
        // C2) are gone -- retargeted onto `return new PwgError(...)`, the
        // same real error-response mechanism this file already uses
        // everywhere else, so upload errors now honor the request's real
        // format=/protocol instead of hardcoding raw JSON regardless.
        'Ws/PwgServer.php' => 1,
        'Ws/WsHelper.php' => 1,
        'Controller/WsController.php' => 1,
        'Bootstrap/UserBootstrap.php' => 2,

        // Workstream C3: Html/HtmlService.php's accessDenied()/badRequest()/
        // pageNotFound()/pageForbidden()/fatalError() and Bootstrap/
        // RedirectService.php's redirectHttp()/redirectHtml() -- the
        // sanctioned HtmlRenderingInterface/RedirectServiceInterface exit
        // mechanism every other controller/service routes through -- no
        // longer call die()/exit() at all. Both now throw
        // Piwigo\Http\ResponseReadyException instead, carrying a real
        // Response up to one of 3 dispatch-context catch points (see that
        // exception class's own docblock for why: exit()/die() skip
        // pending `finally` blocks, which used to leave
        // SentryMiddleware's performance transaction unfinished and
        // ServerTimingMiddleware's header silently skipped on every
        // redirect/error page).

        // AJAX/JSON action endpoints: echo a JSON (or CSV/file) body
        // directly and stop, deliberately not falling through to the
        // full page/template render that follows in the same method.
        'Admin/AdminShell.php' => 1,
        'Admin/MaintenanceSysPageRenderer.php' => 1,
        'Admin/PluginsInstalledPageRenderer.php' => 2,
        'Admin/Maintenance/MaintenanceActionDispatcher.php' => 1,
        'Admin/UserActivityPageRenderer.php' => 1,
        'Controller/Admin/IntroSubController.php' => 1,

        // Admin/Install/InstallWizard.php's own ?dl= database-config-
        // download branch used to be here too (a raw header()/echo/
        // unlink()/exit() sequence) -- found while adding coverage for
        // that exact branch (an exit()-terminated method can't be
        // exercised from inside the same PHP process without exit()ing
        // the whole test run) and converted to throw
        // Piwigo\Http\ResponseReadyException instead, the same C3
        // mechanism this allowlist's own already-converted entries use
        // (public/install.php's entry shell already had a catch point for
        // it, from the mysqli-extension/already-installed guards a few
        // lines below this same branch). Gone from this allowlist.

        // Workstream C3b: Controller/ActionController.php's doError()/304
        // early-return no longer call exit() -- doError() now returns a
        // real ResponseInterface (ResponseFactory::text($str, $code)),
        // and the 304 branch returns ResponseFactory::raw() directly.
        // Simpler than C3a's ResponseReadyException mechanism: doError()
        // is only ever called from this controller's own __invoke(),
        // never a shared class reached from multiple dispatch contexts,
        // so every call site just needed `return $this->doError(...);`.

        // Workstream C3: Bootstrap/RequestBootstrap.php's own 2 raw sites
        // (the install-redirect in configure(), the gallery-locked 503 in
        // finalize()) now throw Piwigo\Http\ResponseReadyException too,
        // caught by the same RequestBootstrap::bootEntryPoint() catch point
        // (below) as configure()/connect()/finalize()'s other
        // short-circuits -- both reachable from exactly one dispatch
        // context (the bootstrap phase). (UserService.php's own 503
        // exit() -- reachable from 3 different dispatch contexts, which is
        // why it was deliberately NOT converted alongside these 2 -- was
        // deleted outright in gap-closure Stage 4g, not converted: the
        // whole lock/wait/503 mechanism it belonged to had nothing left to
        // protect once Stage 4a-4f replaced every `user_cache` column with
        // an independent cache-pool-backed computation.)
        //
        // include/+admin/ deletion batch: bootEntryPoint()'s own catch-and-
        // emit block (`new ResponseEmitter()->emit($e->response()); exit;`)
        // is the exact same statement that used to live at the bottom of
        // include/common.inc.php -- outside src/Piwigo/, so never counted
        // by this test before. Every entry point that used to `include`
        // that seam file now calls this method directly instead, so the
        // one exit() site simply moved into a file this test scans; it is
        // not new debt.
        'Bootstrap/RequestBootstrap.php' => 1,

        // Full legacy template render + exit(), matching the pre-rewrite
        // include-then-die() page shape verbatim. Not part of Workstream
        // C3c (which retired LegacyRenderCapture and its own 3 real
        // callers, Picture/PictureCommentRenderer.php/Controller/Admin/
        // AdminPopuphelpController.php/Controller/PopuphelpController.php
        // -- all 3 now throw ResponseReadyException instead and are gone
        // from this allowlist): this class never used LegacyRenderCapture
        // at all (a raw $template->pparse()+exit() of its own, reached
        // from Bootstrap\RequestBootstrap::finalize() -- catch point 1's
        // own scope, so it's a real future C3 candidate, just not one the
        // plan named).
        'Page/NoPhotoYetRenderer.php' => 1,

        // Controller/ImageDerivativeController.php (i.php): Workstream C3
        // Part III converted every real die()/exit() (ierror() now throws
        // ResponseReadyException, sendDerivative() returns a real
        // ResponseInterface) -- the 2 genuine early-`return` guards that
        // still run before CurrentLogger's own construction (so ierror()
        // itself can't be called yet) return a Response directly, not
        // die()/exit(). Gone from this allowlist.

        // P23 Stage 1e (gap-closure finding #17): the low-level decode/
        // library-availability/upload-validation die() calls formerly here
        // (Admin/Image/ImageGd.php x5, Admin/Image/ImageExtImagick.php x1,
        // Admin/Image/PwgImage.php x2, Admin/Upload/UploadService.php x9)
        // all now throw Piwigo\Admin\Image\ImageProcessingException
        // instead -- the "a hard die() is correct in both real callers"
        // rationale that used to justify them here was the audit's own
        // "materially wrong" finding: Http\Middleware\
        // ExceptionHandlerMiddleware already catches/logs/Sentry-reports
        // any \Throwable for the real HTTP callers (Ws/PwgImages.php,
        // Controller/ImageDerivativeController.php), and Symfony
        // Messenger's own consumer loop does the same for the
        // Job/BatchUploadJob.php background-job caller -- both strict
        // improvements over a silent, unlogged die(). Gone from this
        // allowlist (ImageGd.php/ImageExtImagick.php/PwgImage.php); only
        // UploadService.php still has 1 real site, its own IN_WS branch's
        // exit() (see the Ws/* raw-response mechanism comment above).
        'Admin/Upload/UploadService.php' => 1,

        // Core/ShutdownHandler.php: exit(143), a deliberate, documented
        // signal-termination exit code (128 + SIGTERM), not an error path.
        'Core/ShutdownHandler.php' => 1,
    ];

    $counts = countExitCallsPerFile(__DIR__ . '/../../src/Piwigo');

    // toBe() is a strict === comparison, which cares about key order for
    // associative arrays -- countExitCallsPerFile() returns keys in
    // filesystem-traversal order (ksort()'d), while $allowlist above is
    // deliberately grouped by rationale, not alphabetically. Sort a copy
    // of each so the comparison is order-independent without sacrificing
    // the allowlist's readability.
    ksort($counts);
    $expected = $allowlist;
    ksort($expected);

    expect($counts)->toBe($expected);
});

/**
 * Finds the index of the paren/bracket/brace matching the one at $start
 * (which must itself be an opening bracket character).
 */
function findMatchingBracket(string $s, int $start): int
{
    $opening = $s[$start];
    $closing = ['(' => ')', '[' => ']', '{' => '}'][$opening];
    $depth = 0;
    $len = strlen($s);
    for ($i = $start; $i < $len; $i++) {
        if ($s[$i] === $opening) {
            $depth++;
        } elseif ($s[$i] === $closing) {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return -1;
}

function countTopLevelArgs(string $inner): int
{
    $trimmed = trim($inner);
    if ($trimmed === '') {
        return 0;
    }
    $count = 1;
    $depth = 0;
    for ($i = 0; $i < strlen($inner); $i++) {
        $ch = $inner[$i];
        if (in_array($ch, ['(', '[', '{'], true)) {
            $depth++;
        } elseif (in_array($ch, [')', ']', '}'], true)) {
            $depth--;
        } elseif ($ch === ',' && $depth === 0) {
            $count++;
        }
    }

    return $count;
}

/**
 * Finds the NotificationByMailSender-class anti-pattern: the exact same
 * expensive `new XService(new YRepository(...), new ZService(...), ...)`
 * chain (2+ top-level args, at least one nested `new`) appearing verbatim
 * 2+ times in one file. Single-dependency constructions (`new
 * ActivityService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Activity\ActivityEntity::class))` and similar) are
 * deliberately not flagged even when they recur, matching the
 * already-established "cheap, stateless-ish service, built fresh where
 * needed" design used throughout this codebase.
 *
 * @return list<array{path: string, class: string, count: int}>
 */
function findDuplicateServiceConstructionChains(string $dir): array
{
    $violations = [];
    if (! is_dir($dir)) {
        return $violations;
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

        $seen = [];
        if (preg_match_all('/new\s+\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*Service\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE) === false) {
            continue;
        }

        foreach ($matches[0] as [$matchText, $matchOffset]) {
            $openParen = $matchOffset + strlen($matchText) - 1;
            $closeParen = findMatchingBracket($source, $openParen);
            if ($closeParen === -1) {
                continue;
            }
            $inner = substr($source, $openParen + 1, $closeParen - $openParen - 1);
            if (! str_contains($inner, 'new ') || countTopLevelArgs($inner) < 2) {
                continue;
            }
            $classShort = preg_replace('/^new\s+\\\\?(?:[A-Za-z0-9_]+\\\\)*/', '', $matchText);
            $classShort = rtrim((string) $classShort, '(');
            $normalized = trim((string) preg_replace('/\s+/', ' ', $inner));
            $seen[$classShort . '|' . $normalized][] = $matchOffset;
        }

        foreach ($seen as $key => $positions) {
            if (count($positions) < 2) {
                continue;
            }
            [$classShort] = explode('|', $key, 2);
            $violations[] = ['path' => $file->getPathname(), 'class' => $classShort, 'count' => count($positions)];
        }
    }

    return $violations;
}

test('src/Piwigo/ does not repeat the same multi-dependency service construction chain verbatim within one file', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $violations = findDuplicateServiceConstructionChains($repoRoot . '/src/Piwigo');

    // Phase 1k DI-chain audit (2026-07-19): every file with a repeated
    // multi-arg chain was reviewed. Real gaps were fixed (private
    // DRY-extraction helper methods, or a single reused local variable --
    // never a constructor param, since the classes involved either
    // already had 5-6 constructor params or the dependency was reachable
    // via already-injected state) -- see UserService::permissionService(),
    // MailService::userService(), and similar. What's left below is
    // structurally exempt, not overlooked: the free-function file has no
    // enclosing instance to hang a helper method off of, and InstallWizard
    // runs before any DI container exists (a constructor param there would
    // just move the manual construction to install.php, not remove it).
    // Static `Ws/*.php` WS-method handlers do NOT get a standing exemption
    // -- `self::helperMethod()` DRY-extracts a repeated chain exactly the
    // same way `$this->helperMethod()` would (Legacy Coupling Retirement
    // Phase 4a fixed several real instances this way, e.g.
    // `Ws\PwgCategories::categoryService()`/`Ws\PwgImages::searchService()`
    // calling `self::permissionService()` instead of repeating its chain).
    // Zero-tolerance -- both real prior entries are fixed (Legacy Coupling
    // Retirement Phase 8): Bootstrap/RedirectService.php|UserService in 8a
    // (container-resolved, safe once RequestBootstrap::configure() boots
    // the Kernel as its own first statement); Admin/Install/InstallWizard.php|UserService
    // in 8b, via a plain private $this->userService(?Connection $conn = null)
    // DRY-extraction helper instead -- not container-routed, since
    // PHP-DI's request-shared instance would unsafely cache a Connection
    // built from stale DbCredentials::current() if resolved before
    // InstallWizard::boot()'s own DbCredentials::seed(...) call (from the
    // submitted install form) has run. Matches
    // RequestBootstrap::activityService()'s own established
    // "private helper takes the already-available Connection as a
    // parameter" precedent.
    $allowlist = [];

    $prefixLength = strlen($repoRoot . '/src/Piwigo/');
    $actual = array_map(
        static fn (array $v): string => substr($v['path'], $prefixLength) . '|' . $v['class'],
        $violations
    );
    sort($actual);
    sort($allowlist);

    expect($actual)->toBe($allowlist);
});

test('RequestFactory, ResponseEmitter, and the P9 middleware/pipeline/routing classes declare only readonly state', function (): void {
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

test('every tools/*.php script guards against non-CLI execution (SEC-02)', function (): void {
    // docs/PLAN.md finding #16: tools/build-config-accessors.php
    // had no PHP_SAPI guard and would run its logic (regenerating
    // src/Piwigo/Config/Config.php) under any calling context -- not
    // web-reachable today (tools/ isn't among public/'s symlinks), but a
    // real, literal SEC-02 gap regardless of current reachability. tools/
    // index.php is deliberately excluded -- it's the anti-directory-listing
    // stub (same shape as install/index.php), not a CLI tool, and is
    // designed to run for any web request rather than reject it.
    $root = dirname(__DIR__, 2);
    $scripts = array_values(array_diff(
        globPaths($root . '/tools/*.php'),
        [$root . '/tools/index.php']
    ));

    expect($scripts)->not->toBeEmpty();

    $missingGuard = [];
    foreach ($scripts as $script) {
        $source = file_get_contents($script);
        if ($source === false) {
            throw new RuntimeException("Unreadable file: {$script}");
        }

        if (! str_contains($source, "PHP_SAPI !== 'cli'")) {
            $missingGuard[] = basename($script);
        }
    }

    expect($missingGuard)->toBe([]);
});
