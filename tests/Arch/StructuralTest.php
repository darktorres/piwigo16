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
        'Ws/PwgImages.php' => 5,
        'Ws/PwgServer.php' => 1,
        'Ws/WsHelper.php' => 1,
        'Controller/WsController.php' => 1,
        'Bootstrap/UserBootstrap.php' => 2,

        // Html/HtmlService.php: the sanctioned HtmlRenderingInterface exit
        // mechanism itself (accessDenied()/fatalError()) -- every other
        // controller/service that needs to stop the request with an error
        // page routes through this, not a bare die()/exit() of its own.
        'Html/HtmlService.php' => 2,

        // Bootstrap/RedirectService.php: redirectHttp()/redirectHtml(), the
        // canonical redirect() mechanism used throughout the whole
        // codebase -- same class of sanctioned exit point as HtmlService.
        // Legacy Coupling Retirement Phase 4b: relocated here verbatim from
        // the deleted Http/functions.php (same 2-call count).
        'Bootstrap/RedirectService.php' => 2,

        // AJAX/JSON action endpoints: echo a JSON (or CSV/file) body
        // directly and stop, deliberately not falling through to the
        // full page/template render that follows in the same method.
        'Admin/AdminShell.php' => 1,
        'Admin/MaintenanceSysPageRenderer.php' => 1,
        'Admin/PluginsInstalledPageRenderer.php' => 2,
        'Admin/Maintenance/MaintenanceActionDispatcher.php' => 1,
        'Admin/UserActivityPageRenderer.php' => 1,
        'Admin/Install/InstallWizard.php' => 1,
        'Controller/Admin/IntroSubController.php' => 1,
        'Controller/ActionController.php' => 2,

        // 503 Service-Unavailable raw responses (custom Retry-After
        // header + hand-written body, no template): gallery-locked and
        // user-cache-still-generating pages.
        'Bootstrap/RequestBootstrap.php' => 2,
        'Users/UserService.php' => 1,

        // Full legacy template render + exit(), matching the pre-rewrite
        // include-then-die() page shape verbatim -- LegacyRenderCapture's
        // own docblock covers why die()/exit() *inside* its captured
        // closure is correct, not a bug (PHP flushes the still-open
        // output buffer on exit() by default).
        'Page/NoPhotoYetRenderer.php' => 1,
        'Picture/PictureCommentRenderer.php' => 2,
        'Controller/Admin/AdminPopuphelpController.php' => 2,
        'Controller/PopuphelpController.php' => 1,

        // Controller/ImageDerivativeController.php (i.php): runs before
        // ConfigLoader::applyDefaults() even executes on some paths, so no
        // HtmlRenderingInterface/DI container is available yet -- a raw
        // die()/exit() is the only option this early.
        'Controller/ImageDerivativeController.php' => 6,

        // Image-library internals (Admin/Image/*.php): low-level decode/
        // library-availability guards inside image_gd/image_ext_imagick/
        // pwg_image, reached from both Ws/PwgImages.php (needs a JSON
        // error response, already covered above) and Job/BatchUploadJob.php
        // (a background job with no HTTP response to build) -- a hard
        // die() is correct in both real callers.
        'Admin/Image/image_gd.php' => 2,
        'Admin/Image/image_ext_imagick.php' => 1,
        'Admin/Image/pwg_image.php' => 2,
        'Admin/Upload/UploadService.php' => 10,

        // Frozen historical VersionUpgrade class (Phase 1j's own scope
        // note: "die()/exit() and ConfigDb:: calls deliberately
        // untouched").
        'Admin/Install/VersionUpgrade/UpgradeFrom_1_3_1.php' => 3,

        // upgrade.php's own orchestration: a legacy, top-level-script-
        // style page (no PSR-7 Response object involved), same as the
        // Admin/Install/ orchestration classes generally.
        'Admin/Install/UpgradeRunner.php' => 2,

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
 * ActivityService(new ActivityRepository($conn))` and similar) are
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
    $allowlist = [
        // Free functions: no enclosing instance, nothing to inject into.
        // Legacy Coupling Retirement Phase 4b: redirect_html()'s early-crash
        // fallback (2 UserService construction sites) moved verbatim from
        // the deleted Http/functions.php into Bootstrap/RedirectService.php
        // -- same structural exemption, new file.
        'Bootstrap/RedirectService.php|UserService',

        // Pre-installation, no DI container exists yet (matches the
        // Env/FilesystemHelper/MysqliDb precedent documented on this
        // class's own docblock).
        'Admin/Install/InstallWizard.php|UserService',
    ];

    $prefixLength = strlen($repoRoot . '/src/Piwigo/');
    $actual = array_map(
        static fn (array $v): string => substr($v['path'], $prefixLength) . '|' . $v['class'],
        $violations
    );
    sort($actual);
    sort($allowlist);

    expect($actual)->toBe($allowlist);
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
