<?php

declare(strict_types=1);

use Piwigo\Admin\LoadedPluginsMiddleware;
use Piwigo\Bootstrap\FinalizeBridgeMiddleware;
use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Bootstrap\UserResolutionMiddleware;
use Piwigo\Http\BaselineSecurityHeaders;
use Piwigo\Http\Middleware\ApiErrorMiddleware;
use Piwigo\Http\Middleware\ConfigBootstrapMiddleware;
use Piwigo\Http\Middleware\ControllerInvokerMiddleware;
use Piwigo\Http\Middleware\ExceptionHandlerMiddleware;
use Piwigo\Http\Middleware\LanguageMiddleware;
use Piwigo\Http\Middleware\PluginBootstrapMiddleware;
use Piwigo\Http\Middleware\RoutingMiddleware;
use Piwigo\Http\Middleware\SecurityHeadersMiddleware;
use Piwigo\Http\MiddlewarePipeline;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;
use Piwigo\Http\ResponseFactory;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Routing\Router;
use Piwigo\Routing\RouteResult;
use Psr\EventDispatcher\EventDispatcherInterface;

arch()
    ->expect('Piwigo')
    ->toUseStrictTypes();

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
    // Deliberately missing #[\Override] -- that absence is exactly what
    // this test asserts findMissingOverrideAttributes() catches. See
    // psalm.xml's own MissingOverrideAttribute suppression for this file.
    $withoutAttribute = new class() implements Countable {
        public function count(): int
        {
            return 0;
        }
    };

    expect(findMissingOverrideAttributes($withoutAttribute::class))->toBe([$withoutAttribute::class . '::count()']);
});

test('findMissingOverrideAttributes() accepts a present #[\Override]', function (): void {
    $withAttribute = new class() implements Countable {
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

    expect($violations)
        ->toBe([]);
});

// Kernel::container() is a service locator -- services must receive
// dependencies via constructor injection instead. It exists only to let
// Bootstrap/ reach into the container before injection is possible; every
// root entry script (index.php included) reaches it only indirectly,
// through a Bootstrap/ class.

/**
 * @return list<array{path: string, line: int}>
 */
function findCallSites(string $dir, string $needle): array
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

        $lines = file($file->getPathname());
        foreach ($lines !== false ? $lines : [] as $lineNumber => $line) {
            if (str_contains($line, $needle)) {
                $hits[] = [
                    'path' => $file->getPathname(),
                    'line' => $lineNumber + 1,
                ];
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
                $hits[] = [
                    'path' => $pathname,
                    'line' => $lineNumber + 1,
                ];
            }
        }
    }

    return $hits;
}

/**
 * bin/piwigo has no .php extension, so it's invisible to
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
                $hits[] = [
                    'path' => $pathname,
                    'line' => $lineNumber + 1,
                ];
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
    // index.php is pure bootstrap + dispatch through
    // RequestBootstrap::bootEntryPoint() and never calls
    // Kernel::container() directly itself -- every real container access
    // already goes through a Bootstrap/ class.
    //
    // A handful of classes outside Bootstrap/ each carry ONE narrow,
    // explicitly-tracked exception: a transitional `@deprecated
    // ...Static()` shim method (see e.g.
    // Piwigo\Core\InstallationFlag::isActiveStatic()'s own docblock) that
    // lets a not-yet-converted caller elsewhere keep working unchanged.
    // Every entry below is removed once that class's shim is deleted --
    // this allow-list should shrink back to empty, not grow unbounded.
    $repoRoot = __DIR__ . '/../..';

    $shimAllowedFiles = [
        '/src/Piwigo/Admin/Image/ImageBackend.php',
        '/src/Piwigo/Core/FilesystemHelper.php',
        '/src/Piwigo/Core/CoverageCollector.php',
        '/src/Piwigo/Core/ErrorCollector.php',
        '/src/Piwigo/Core/UniqueExecLock.php',
        '/src/Piwigo/Db/DbConnection.php',
        '/src/Piwigo/Session/SessionService.php',
        '/src/Piwigo/Lang/Translator.php',
        '/src/Piwigo/PluginConfig/EventDispatcher.php',
        '/src/Piwigo/Image/SrcImage.php',
        '/src/Piwigo/Image/DerivativeImage.php',
        '/src/Piwigo/Template/Template.php',
        '/src/Piwigo/Html/HtmlService.php',
        '/src/Piwigo/Page/PageHeaderRenderer.php',
        '/src/Piwigo/Mail/MailService.php',
        '/src/Piwigo/Url/UrlService.php',
        '/src/Piwigo/Users/UserService.php',
        '/src/Piwigo/Category/CategoryService.php',
        '/src/Piwigo/Tag/TagService.php',
        '/src/Piwigo/Image/ImageService.php',
        '/src/Piwigo/Activity/ActivityService.php',
        '/src/Piwigo/Core/Logger.php',
        '/src/Piwigo/Core/DateHelper.php',
        '/src/Piwigo/Cache/PermissionCacheInvalidator.php',
        '/src/Piwigo/Auth/CookieService.php',
        '/src/Piwigo/Config/ConfigService.php',
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

    expect(describeCallSites($disallowed))
        ->toBe([]);
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

    expect(describeCallSites($disallowed))
        ->toBe([]);
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

    expect(describeCallSites($hits))
        ->toBe([]);
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

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('CurrentConfig::reset() is only called from tests/', function (): void {
    // Mirrors the Kernel::reset() rule above -- reset() exists purely for
    // test isolation between cases; production code must never touch it.
    // reset() is a real instance method, not a static call, so this
    // static-call-syntax string literal can never match real code again --
    // this test is permanently, deliberately vacuous (same shape as
    // MailService::reset()'s own equivalent test), not an oversight.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'CurrentConfig::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'CurrentConfig::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'CurrentConfig::reset('),
    ];

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('SessionService::reset() is only called from tests/', function (): void {
    // Same test-isolation rationale as CurrentConfig::reset() above.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'SessionService::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'SessionService::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'SessionService::reset('),
    ];

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('UniqueExecLock::reset() is only called from tests/', function (): void {
    // Same test-isolation rationale as CurrentConfig::reset() above.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'UniqueExecLock::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'UniqueExecLock::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'UniqueExecLock::reset('),
    ];

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('DbConnection::useTestOverride() is only called from tests/', function (): void {
    // useTestOverride() exists purely so tests/Support/DbTransactionTestOverride.php
    // can make DbConnection::build() return one shared, already-transactional
    // connection for the duration of a single Unit test; production code
    // must never touch it.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'DbConnection::useTestOverride('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'DbConnection::useTestOverride('),
        ...findCallSitesInBinFiles($repoRoot, 'DbConnection::useTestOverride('),
    ];

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('CurrentConfigService::reset() is only called from tests/', function (): void {
    // Same test-isolation rationale as CurrentConfig::reset() above.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'CurrentConfigService::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'CurrentConfigService::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'CurrentConfigService::reset('),
    ];

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('Lang::reset() is only called from tests/', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'Lang::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'Lang::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'Lang::reset('),
    ];

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('PageState::reset() is only called from tests/', function (): void {
    // Same test-isolation rationale as SessionService::reset() above --
    // PageState genuinely accumulates real per-request state (unlike
    // DeploymentPolicy/StorageRegistry, which had nothing left to reset
    // once container-shared), so its instance reset() stayed a real
    // method rather than being deleted outright.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'PageState::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'PageState::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'PageState::reset('),
    ];

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('src/Piwigo/ contains no InputValidator::createStatic() calls', function (): void {
    // InputValidator's createStatic() shim is deleted -- every real
    // construction site (39 pure Request DTO
    // fromGlobals()/fromArray()/fromArrays() static factories, plus 4 real
    // classes calling it directly -- Piwigo\Admin\AdminShell,
    // Piwigo\Controller\Admin\{BatchManagerSubController,
    // ConfigurationSubController,NotificationByMailSubController}) takes
    // InputValidator as an explicit constructor/method parameter instead.
    // Zero-tolerance regression guard, not a shrinking allow-list.
    $repoRoot = __DIR__ . '/../..';

    $hits = findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'InputValidator::createStatic(');

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('the Latte-analysis tooling contains no raw absolute-FQCN string literals', function (): void {
    // A prior Rector run mechanically rewrote 3 literal FQCN strings
    // ('\Piwigo\...\Foo') into Foo::class constants -- normally safe,
    // but these particular strings get spliced directly into *generated
    // PHP source text* (compiled-template @var docblocks, a shim-class
    // call prefix), and ::class alone never carries the leading
    // backslash the emitted code needs. The fix ('\\' . Foo::class,
    // see ContextVariableExtractor::frameworkGlobals()/
    // LatteTemplateCompiler::SHIMS/PhpStanLatteCompileCommand::execute())
    // is a concatenation expression, not a bare string literal, so it
    // can't regress the same way. Uses plain findCallSites()
    // (str_contains(), no comment/string exclusion), not
    // findCallSitesOutsideComments() above -- the anti-pattern being
    // guarded against *is* string-literal content, so a helper that
    // blanks strings out first would never find it.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/tools/phpstan/Latte', "'\\Piwigo\\"),
        ...findCallSites($repoRoot . '/tools/phpstan/Latte', "'\\Latte\\"),
        ...findCallSites($repoRoot . '/src/Piwigo/Command', "'\\Piwigo\\"),
        ...findCallSites($repoRoot . '/src/Piwigo/Command', "'\\Latte\\"),
    ];

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('MailService::reset() is only called from tests/', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'MailService::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'MailService::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'MailService::reset('),
    ];

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('CurrentTemplate::reset() is only called from tests/', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'CurrentTemplate::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'CurrentTemplate::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'CurrentTemplate::reset('),
    ];

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('RootPathOverride::reset() is only called from tests/', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'RootPathOverride::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'RootPathOverride::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'RootPathOverride::reset('),
    ];

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('CurrentUser::reset() is only called from tests/', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSites($repoRoot . '/src/Piwigo', 'CurrentUser::reset('),
        ...findCallSitesInRootPhpFiles($repoRoot, 'CurrentUser::reset('),
        ...findCallSitesInBinFiles($repoRoot, 'CurrentUser::reset('),
    ];

    expect(describeCallSites($hits))
        ->toBe([]);
});

// AccessControl has no circular dependency: the 8 read-only checks --
// isAdmin()/isAGuest()/isClassicUser()/isWebmaster()/canManageComment()/
// etc. -- live on Auth\AccessLevelChecker (CurrentUser/CurrentConfig
// only), which every real caller takes via constructor injection.
// checkStatus()/accessDenied() are the only methods that need
// HtmlRenderingInterface/RedirectServiceInterface and stay on
// AccessControl itself.

// src/Piwigo/ is the typed source of truth for the retired
// include/constants.php constants (AppInfo/AccessLevel/ActivitySystem/
// ValidationPattern/Tables/Config accessors) -- a regression guard, not a
// migration. Legacy include//admin/ root entry points are explicitly NOT
// scanned here (this test only covers src/Piwigo/) -- see the sibling
// "no PHPWG_ROOT_PATH/PWG_LOCAL_DIR reads" test below, zero-tolerance.
//
// PHPWG_ROOT_PATH and PWG_LOCAL_DIR are both fully retired: Piwigo\Core\Paths
// replaces them -- DI-constructed classes get Paths threaded through
// their constructor, test code reads CurrentPathsTestFactory::get().
// Controller/ImageDerivativeController.php's own 2 URL-generation sites
// use UrlService::getAbsoluteRootUrl(false) instead, the same
// request-depth-independent mechanism (cookiePath(),
// SCRIPT_NAME/REDIRECT_URL-based) this codebase uses elsewhere --
// zero-tolerance, matching the IN_ADMIN/IN_WS/... test below.
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
        // string literals (a needle like "define(" or "PHPWG_INSTALLED" can
        // appear as plain text inside one, e.g. an error message or a
        // generated-file template, without that being a real call/read),
        // heredoc bodies, and inline HTML are blanked out (newlines
        // preserved, so line numbers stay exact) before the text search.
        // Strictly more precise than the previous comment-prefix line
        // heuristic: every real call still matches.
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
                $hits[] = [
                    'path' => $file->getPathname(),
                    'line' => $lineNumber + 1,
                ];
            }
        }
    }

    return $hits;
}

test('src/Piwigo/ contains no define() calls', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $hits = findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'define(');

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('src/Piwigo/ contains no PHPWG_ROOT_PATH/PWG_LOCAL_DIR reads', function (): void {
    // Both constants are fully retired, zero-tolerance --
    // Controller/ImageDerivativeController.php's own last 2 sites (see
    // this file's own comment a few lines above) are gone too. A hit
    // anywhere means a new raw read was introduced and must be migrated
    // onto Paths (or UrlService::getAbsoluteRootUrl(false) for URL
    // generation) instead of allowlisted.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'PHPWG_ROOT_PATH'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'PWG_LOCAL_DIR'),
    ];

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('src/Piwigo/ reads $_POST/$_GET/$_REQUEST/$_FILES only inside a Request DTO or a documented exception', function (): void {
    // Every page controller/domain service reads $_GET/$_POST/$_REQUEST/
    // $_FILES only through a validating `{Module}/Request/{Name}` DTO's own
    // `fromGlobals()` (the sole legitimate raw read a DTO class makes for
    // itself -- this scan only covers files OUTSIDE any `Request/`
    // directory, matching `RequestFactory::fromGlobals()`'s own
    // established "the wrapper's internals are exempt from the rule it
    // enforces on everyone else" shape). A hit anywhere else means a new
    // raw read was introduced and must be migrated onto a Request DTO,
    // not allowlisted -- the 3 files below are the only real exceptions
    // that survived a full repo audit, each already documented at its own
    // call site:
    //   - Admin/AdminShell.php: runDispatch()'s own page-slug alias
    //     rewriting ($_GET['page']/['section']/['tab']) -- load-bearing,
    //     must survive into RequestFactory::fromGlobals()'s own later
    //     read in the same method.
    //   - Bootstrap/RequestBootstrap.php: the once-per-request magic-
    //     quotes-style superglobal sanitization pass (array_walk_recursive
    //     over the whole $_GET/$_POST), which necessarily runs before any
    //     Request DTO in the app could read either array -- the same
    //     "earliest possible bootstrap stage" category as
    //     RequestFactory::fromGlobals() itself.
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
        'Bootstrap/RequestBootstrap.php',
        'Admin/BatchManagerGlobalPageRenderer.php',
    ];
    $unexpected = array_values(array_filter(
        $hits,
        static fn (array $hit): bool => ! str_contains($hit['path'], '/Request/')
            && array_all($allowlistedSuffixes, static fn (string $suffix): bool => ! str_ends_with($hit['path'], $suffix))
    ));

    expect(describeCallSites($unexpected))
        ->toBe([]);
});

test('src/Piwigo/ contains no raw IN_ADMIN/IN_WS/PHPWG_INSTALLED/PWG_CHARSET/PHPWG_URL/PHPWG_DOMAIN/PEM_URL reads', function (): void {
    // All 7 are fully retired, zero-tolerance -- typed replacements
    // (Piwigo\Core\AdminContext/ApiContext/InstallationFlag/
    // AppInfo::DOMAIN/AppInfo::URL/Bootstrap\RequestBootstrap::pemUrl())
    // cover every real caller, and PWG_CHARSET's own 2 former readers
    // (CharsetHelper::getPwgCharset()/StringHelper::pwgTransliterate())
    // now use the literal 'utf-8' directly instead, so unlike
    // PHPWG_ROOT_PATH/PWG_LOCAL_DIR above there's no legitimate exception
    // left to allowlist. The 2 entry-shell define()s that still
    // legitimately exist (admin.php/admin/popuphelp.php call
    // AdminContext::mark(), not define()) are outside src/Piwigo/, so this
    // scan never sees them; install.php itself has no define()s left at
    // all. DB_CHARSET/DB_COLLATE never had a raw read anywhere in
    // src/Piwigo/ to begin with, so they don't need their own scan entries
    // here.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'IN_ADMIN'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'IN_WS'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'PHPWG_INSTALLED'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'PWG_CHARSET'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'PHPWG_URL'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'PHPWG_DOMAIN'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'PEM_URL'),
    ];

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('src/Piwigo/ contains no global $filter/$pwg_loaded_plugins/$template/$page declarations', function (): void {
    // All 4 clusters are fully retired, not just reduced --
    // $filter/$pwg_loaded_plugins onto Piwigo\Core\FilterState/
    // Piwigo\Admin\LoadedPlugins, $template onto
    // Piwigo\Template\CurrentTemplate, $page either deleted as
    // confirmed-dead code or converted to a plain per-method local or a
    // private static guard (Admin\Maintenance\
    // FilesystemIntegrityChecker::fsQuickCheck()). Zero-tolerance, no
    // allowlist needed.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'global $filter'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'global $pwg_loaded_plugins'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'global $template'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'global $page'),
    ];

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('src/Piwigo/ contains no global $conf/$prefixeTable/$last_time/$t2 declarations', function (): void {
    // Table names are plain literal strings now -- no prefix, no indirection.
    // Config keys only ever set by a site's own local/config/config.inc.php
    // (never mirrored into CurrentConfig) are read via a direct `@include`
    // of that file (e.g. Admin\UserListPageRenderer), not a global. $t2
    // (RequestBootstrap::configure()) is an explicit parameter, not a
    // global. Zero-tolerance, no allowlist needed.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'global $conf'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'global $prefixeTable'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'global $last_time'),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'global $t2'),
    ];

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('src/Piwigo/ contains no bare add_event_handler()/trigger_change()/trigger_notify() calls', function (): void {
    // The free-function bridge (src/Piwigo/PluginConfig/functions.php) is
    // deleted -- every real call site takes EventDispatcher via
    // constructor injection and calls {addTypedHandler,removeTypedHandler,
    // dispatch}() directly. deptrac.yaml
    // places EventDispatcher in L1Infrastructure (split from its
    // namespace-mate PluginRepository) since real callers live in
    // L1Infrastructure/L2aCoreDomain. Zero-tolerance, no allowlist needed.
    $repoRoot = __DIR__ . '/../..';

    $hits = [
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'add_event_handler('),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'trigger_change('),
        ...findCallSitesOutsideComments($repoRoot . '/src/Piwigo', 'trigger_notify('),
    ];

    expect(describeCallSites($hits))
        ->toBe([]);
});

test('EventDispatcher implements real PSR-14 EventDispatcherInterface conformance', function (): void {
    // EventDispatcher has real Psr\EventDispatcher\
    // EventDispatcherInterface conformance via dispatch(), the single verb
    // for both value-transform and fire-and-forget dispatch. No separate
    // scan for string-keyed handler-registration call sites is needed:
    // composer analyse (PHPStan, whole codebase, level 10) already
    // guarantees a call to a method that doesn't exist is a real
    // static-analysis error, not something this test needs to duplicate
    // by hand.
    expect(new ReflectionClass(EventDispatcher::class)->implementsInterface(EventDispatcherInterface::class))
        ->toBeTrue();
});

/**
 * Token-aware, unlike findCallSitesOutsideComments()'s plain (post-
 * blanking) substring match: several retired free-function names collide
 * as a bare substring with a real, still-legitimate OOP method call of
 * the identical short name -- most visibly `redirect(`, which matches
 * both a bare `redirect()` call and every real
 * `$this->redirectService->redirect(...)` call. Walks tokens for a
 * T_STRING name match not preceded by ->/::/function/backslash,
 * immediately followed by `(`, to distinguish the two.
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
        $hit = array_any($names, fn (string $name): bool => str_contains($source, $name));
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
            $hits[] = [
                'path' => $file->getPathname(),
                'line' => $tok[2],
            ];
        }
    }

    return $hits;
}

test('src/Piwigo/ contains no bare calls to any retired free function', function (): void {
    // The 4 composer.json autoload.files free-function bridges --
    // Category/functions.php, Http/functions.php, Url/functions.php,
    // Lang/functions.php -- are deleted; every real call site now calls a
    // real class method instead. Zero-tolerance, no allowlist needed,
    // using findBareCallSites() instead of findCallSitesOutsideComments()
    // since several of these names (most notably `redirect`) collide as a
    // bare substring with a real, still-legitimate method call of the
    // same short name.
    $repoRoot = __DIR__ . '/../..';

    $retiredFunctionNames = [
        // Category/functions.php
        'get_subcat_ids', 'get_cat_info', 'get_uppercat_ids', 'set_cat_visible',
        'set_cat_status', 'set_random_representant', 'create_virtual_category',
        // Http/functions.php
        'redirect', 'redirect_html', 'redirect_http',
        // Url/functions.php
        'get_root_url', 'get_absolute_root_url', 'add_url_params', 'make_index_url',
        'duplicate_index_url', 'duplicate_picture_url', 'make_picture_url',
        'parse_section_url', 'parse_well_known_params_url', 'get_action_url',
        'get_element_url', 'set_make_full_url', 'unset_make_full_url', 'embellish_url',
        'get_gallery_home_url', 'get_query_string_diff', 'url_is_remote', 'get_user_favorites',
        // Lang/functions.php
        'l10n', 'l10n_dec',
    ];

    $hits = findBareCallSites($repoRoot . '/src/Piwigo', $retiredFunctionNames);

    expect(describeCallSites($hits))
        ->toBe([]);
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
    // Every site below is grouped by rationale, not alphabetically. A new
    // file calling die()/exit(), or a changed count in a file already
    // listed here, fails this test until reviewed and reflected here
    // explicitly. This is a count-based allowlist, not a line-based one:
    // line numbers drift with unrelated edits, call-site counts don't (a
    // genuinely new call site always changes the count).
    $allowlist = [
        // Html/HtmlService.php's accessDenied()/badRequest()/
        // pageNotFound()/pageForbidden()/fatalError() and Bootstrap/
        // RedirectService.php's redirectHttp()/redirectHtml() -- the
        // sanctioned HtmlRenderingInterface/RedirectServiceInterface exit
        // mechanism every other controller/service routes through -- never
        // call die()/exit(). Both throw Piwigo\Http\ResponseReadyException
        // instead, carrying a real Response up to one of 3 dispatch-context
        // catch points (see that exception class's own docblock for why:
        // exit()/die() skip pending `finally` blocks, which would leave
        // SentryMiddleware's performance transaction unfinished and
        // ServerTimingMiddleware's header silently skipped on every
        // redirect/error page).

        // AJAX/JSON action endpoints: echo a JSON (or CSV/file) body
        // directly and stop, deliberately not falling through to the
        // full page/template render that follows in the same method.
        'Admin/AdminShell.php' => 1,
        'Admin/PluginsInstalledPageRenderer.php' => 2,
        'Admin/Maintenance/MaintenanceActionDispatcher.php' => 1,
        'Admin/UserActivityPageRenderer.php' => 1,
        'Controller/Admin/IntroSubController.php' => 1,

        // Controller/ActionController.php's doError()/304 early-return
        // don't call exit() -- doError() returns a real ResponseInterface
        // (ResponseFactory::text($str, $code)), and the 304 branch returns
        // ResponseFactory::raw() directly. doError() is only ever called
        // from this controller's own __invoke(), never a shared class
        // reached from multiple dispatch contexts, so every call site
        // just needs `return $this->doError(...);`.

        // Bootstrap/RequestBootstrap.php's 2 raw sites (the
        // install-redirect in configure(), the gallery-locked 503 in
        // finalize()) throw Piwigo\Http\ResponseReadyException, caught by
        // the same RequestBootstrap::bootEntryPoint() catch point as
        // configure()/connect()/finalize()'s other short-circuits -- both
        // reachable from exactly one dispatch context (the bootstrap
        // phase). bootEntryPoint()'s own catch-and-emit block (`new
        // ResponseEmitter()->emit($e->response()); exit;`) is this file's
        // one exit() site.
        'Bootstrap/RequestBootstrap.php' => 1,

        // Full legacy template render + exit(), matching the
        // include-then-die() page shape: a one-shot
        // Renderer::render()/Template::finalizeHtml()+exit() reached
        // from Bootstrap\RequestBootstrap::finalize().
        'Page/NoPhotoYetRenderer.php' => 1,

        // Controller/ImageDerivativeController.php (i.php): every real
        // die()/exit() is gone -- ierror() throws ResponseReadyException,
        // sendDerivative() returns a real ResponseInterface, and the 2
        // early-`return` guards that run before CurrentLogger's own
        // construction (so ierror() itself can't be called yet) return a
        // Response directly.

        // Low-level decode/library-availability/upload-validation errors
        // throw Piwigo\Admin\Image\ImageProcessingException instead of
        // die(): Http\Middleware\ExceptionHandlerMiddleware catches/logs/
        // Sentry-reports any \Throwable for real HTTP callers (e.g.
        // Controller/ImageDerivativeController.php), and Symfony
        // Messenger's own consumer loop does the same for the
        // Job/BatchUploadJob.php background-job caller.
        // Admin/Upload/UploadService.php throws
        // Piwigo\Admin\Upload\UnsupportedMediaTypeException, a sibling of
        // ImageProcessingException, mapped onto a 415 problem+json response
        // by TusUploadCompletionService::completePhoto()'s own catch clause.

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

    expect($counts)
        ->toBe($expected);
});

/**
 * Finds the index of the paren/bracket/brace matching the one at $start
 * (which must itself be an opening bracket character).
 */
function findMatchingBracket(string $s, int $start): int
{
    $opening = $s[$start];
    $closing = [
        '(' => ')',
        '[' => ']',
        '{' => '}',
    ][$opening];
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
            $violations[] = [
                'path' => $file->getPathname(),
                'class' => $classShort,
                'count' => count($positions),
            ];
        }
    }

    return $violations;
}

test('src/Piwigo/ does not repeat the same multi-dependency service construction chain verbatim within one file', function (): void {
    $repoRoot = __DIR__ . '/../..';

    $violations = findDuplicateServiceConstructionChains($repoRoot . '/src/Piwigo');

    // Zero-tolerance: a file may not repeat the same multi-dependency
    // service construction chain verbatim -- DRY-extract it into a
    // private helper method (`$this->helperMethod()`, or a static one for
    // a static caller) or a single reused local variable instead.
    $allowlist = [];

    $prefixLength = strlen($repoRoot . '/src/Piwigo/');
    $actual = array_map(
        static fn (array $v): string => substr($v['path'], $prefixLength) . '|' . $v['class'],
        $violations
    );
    sort($actual);
    sort($allowlist);

    expect($actual)
        ->toBe($allowlist);
});

test('RequestFactory, ResponseEmitter, and the middleware/pipeline/routing classes declare only readonly state', function (): void {
    // SEC-60 (worker-isolation, partial verification): these classes must stay
    // free of MUTABLE state so a future FrankenPHP worker loop can reuse them
    // across requests without cross-request state bleed. readonly properties
    // set once at construction and never mutated are not a bleed risk, so
    // the middleware/pipeline classes (SecurityHeadersMiddleware/
    // RoutingMiddleware/ControllerInvokerMiddleware/MiddlewarePipeline) may
    // have constructor-injected readonly properties. Kernel's own
    // static state is the sanctioned exception -- it's request-isolated via
    // Kernel::reset(), which a worker loop will call between requests once
    // that mode exists.
    //
    // ConfigBootstrapMiddleware/PluginBootstrapMiddleware/
    // LoadedPluginsMiddleware/UserResolutionMiddleware/LanguageMiddleware/
    // FinalizeBridgeMiddleware are workstream C3 Phase 1's own new
    // bootstrap-phase middleware, formerly Bootstrap\RequestBootstrap::
    // connect()/finalize()'s procedural body -- the same worker-isolation
    // concern applies to them now that they run as real, container-shared
    // middleware instances instead of one-shot static method calls.
    foreach ([
        RequestFactory::class,
        ResponseEmitter::class,
        ResponseFactory::class,
        MiddlewarePipeline::class,
        BaselineSecurityHeaders::class,
        ExceptionHandlerMiddleware::class,
        SecurityHeadersMiddleware::class,
        RoutingMiddleware::class,
        ApiErrorMiddleware::class,
        ControllerInvokerMiddleware::class,
        Router::class,
        RouteResult::class,
        RequestPipeline::class,
        ConfigBootstrapMiddleware::class,
        PluginBootstrapMiddleware::class,
        LoadedPluginsMiddleware::class,
        UserResolutionMiddleware::class,
        LanguageMiddleware::class,
        FinalizeBridgeMiddleware::class,
    ] as $fqcn) {
        $mutableProperties = array_filter(
            new ReflectionClass($fqcn)
                ->getProperties(),
            static fn (ReflectionProperty $property): bool => ! $property->isReadOnly()
        );
        $violations = array_map(
            static fn (ReflectionProperty $property): string => "{$fqcn}::\${$property->getName()}",
            $mutableProperties
        );

        expect(array_values($violations))
            ->toBe([]);
    }
});

test('every tools/*.php script guards against non-CLI execution (SEC-02)', function (): void {
    // tools/build-config-accessors.php
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

    expect($scripts)
        ->not->toBeEmpty();

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

    expect($missingGuard)
        ->toBe([]);
});
