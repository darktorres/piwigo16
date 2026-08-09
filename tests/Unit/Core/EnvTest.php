<?php

declare(strict_types=1);

use Piwigo\Core\Env;

/**
 * No prior EnvTest.php existed. testModeHeader()/testModeIsActive()/
 * testModeEnvFile()/testModeInstalledStamp()/now() and loadEnvFile()'s own
 * real-load path already have full coverage through other suites'
 * bootstrap flows -- this file closes the two remaining gaps:
 * loadEnvFile()'s missing-file early return, and mergeIntoEnvFile()'s
 * existing-line-preservation and write-failure branches.
 */
function envTestRrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    @chmod($dir, 0o755);
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        if (is_dir($path)) {
            envTestRrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir() . '/piwigo-env-test-' . bin2hex(random_bytes(8));
    mkdir($this->root, 0o777, true);
});

afterEach(function (): void {
    envTestRrmdir($this->root);
});

test('testModeHeader returns null for a malformed header value, not the raw string', function (): void {
    // Kills line 35's RemoveEarlyReturn: without the early return,
    // control falls through past the closing brace straight to `return
    // $header;`, returning the raw, unvalidated header string instead.
    $original = $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null;
    $_SERVER['HTTP_X_PIWIGO_ENV'] = 'not-a-valid-test-header';

    try {
        expect(Env::testModeHeader())->toBeNull();
    } finally {
        if ($original === null) {
            unset($_SERVER['HTTP_X_PIWIGO_ENV']);
        } else {
            $_SERVER['HTTP_X_PIWIGO_ENV'] = $original;
        }
    }
});

test('testModeEnvFile resolves to .env.<header> when test mode is active', function (): void {
    // tests/bootstrap.php sets HTTP_X_PIWIGO_ENV=test for the whole
    // suite -- kills line 67's TernaryNegated (would wrongly resolve to
    // null, hence '.env', even though test mode really is active here)
    // and line 68's IdenticalToNotIdentical/every concat mutant on
    // '.env.' . $value (any of them would produce something other than
    // the real '.env.test').
    expect(Env::testModeEnvFile())->toBe('.env.test');
});

test('testModeEnvFile resolves to .env when test mode is not active', function (): void {
    $original = $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null;
    unset($_SERVER['HTTP_X_PIWIGO_ENV']);

    try {
        expect(Env::testModeEnvFile())->toBe('.env');
    } finally {
        if ($original !== null) {
            $_SERVER['HTTP_X_PIWIGO_ENV'] = $original;
        }
    }
});

test('now returns the frozen PIWIGO_TEST_NOW instant when test mode is active', function (): void {
    // tests/.env.test sets PIWIGO_TEST_NOW=2026-08-01T00:00:00 for the
    // whole suite -- kills line 89's IfNegated (would skip the whole
    // freeze block even though test mode really is active) and line
    // 92's RemoveEarlyReturn (would fall through to the real wall clock
    // a few lines below instead of returning the frozen instant).
    expect(Env::now()->format('Y-m-d H:i:s'))->toBe('2026-08-01 00:00:00');
});

test('now falls back to a real clock when PIWIGO_TEST_NOW is unset', function (): void {
    // Kills line 91's FalseToTrue/2x NotIdenticalToIdentical/
    // BooleanAndToBooleanOr/IfNegated: getenv() returning false (unset)
    // must NOT be treated as a real frozen value.
    $original = getenv('PIWIGO_TEST_NOW');
    putenv('PIWIGO_TEST_NOW');

    try {
        $before = new DateTime();
        $result = Env::now();
        $after = new DateTime();

        expect($result->getTimestamp())->toBeGreaterThanOrEqual($before->getTimestamp())
            ->and($result->getTimestamp())->toBeLessThanOrEqual($after->getTimestamp());
    } finally {
        putenv($original === false ? 'PIWIGO_TEST_NOW' : 'PIWIGO_TEST_NOW=' . $original);
    }
});

test('now falls back to a real clock when PIWIGO_TEST_NOW is explicitly empty', function (): void {
    // Line 91's own EmptyStringToNotEmpty (the '' sentinel mutated to a
    // placeholder) is confirmed-equivalent for this exact scenario,
    // verified live: `new DateTime('')` and `new DateTime()` both
    // resolve to "now" (PHP's own date parser treats an empty string
    // the same as no argument), so even if the mutant wrongly took the
    // frozen-return branch with $frozen = '', the result would still be
    // real current time either way. Kept as a real regression test for
    // the empty-value fallback itself, not credited with killing this
    // specific mutant.
    $original = getenv('PIWIGO_TEST_NOW');
    putenv('PIWIGO_TEST_NOW=');

    try {
        $before = new DateTime();
        $result = Env::now();
        $after = new DateTime();

        expect($result->getTimestamp())->toBeGreaterThanOrEqual($before->getTimestamp())
            ->and($result->getTimestamp())->toBeLessThanOrEqual($after->getTimestamp());
    } finally {
        putenv($original === false ? 'PIWIGO_TEST_NOW' : 'PIWIGO_TEST_NOW=' . $original);
    }
});

test('loadEnvFile is a safe no-op when the resolved env file does not exist', function (): void {
    // No .env.test file exists under this fresh, empty root -- loadEnvFile()
    // must return without ever constructing a Dotenv instance.
    expect(fn () => Env::loadEnvFile($this->root))->not->toThrow(Throwable::class);
});

/**
 * Confirmed-equivalent (verified live via a temporary
 * sed-applied mutation): line 108's UnwrapRtrim, dropping the
 * `rtrim($root, '/\\')` call -- a root with a trailing slash still
 * resolves to the same real file on this filesystem either way (a
 * double slash is POSIX-normalized identically to a single one),
 * confirmed live with a trailing-slash root that loaded successfully
 * regardless.
 */
test('loadEnvFile actually loads the resolved env file into the process environment', function (): void {
    // Kills line 109's own concat mutants (ConcatRemoveLeft/Right x2
    // each): any of them builds the wrong path, so the real file at
    // "$root/.env.test" would never be found and loaded.
    $root = $this->root;
    file_put_contents($root . '/.env.test', "ENV_TEST_LOAD_CHECK=loaded\n");

    Env::loadEnvFile($root);

    $loaded = getenv('ENV_TEST_LOAD_CHECK');
    putenv('ENV_TEST_LOAD_CHECK'); // don't leak into later tests
    expect($loaded)->toBe('loaded');
});

test('mergeIntoEnvFile replaces matching keys while preserving every other existing line untouched', function (): void {
    $envFile = $this->root . '/.env.merge-test';
    file_put_contents($envFile, "DB_HOST=localhost\nDB_NAME=piwigo\nUNRELATED_VAR=keepme\n");

    $result = Env::mergeIntoEnvFile($envFile, [
        'DB_NAME' => 'piwigo_new',
        'DB_USER' => 'admin',
    ]);

    expect($result)->toBeTrue()
        ->and(file_get_contents($envFile))->toBe(
            "DB_NAME=piwigo_new\nDB_USER=admin\nDB_HOST=localhost\nUNRELATED_VAR=keepme\n"
        );
});

test('mergeIntoEnvFile strips newlines and null bytes from values before writing, preventing env-file injection', function (): void {
    $envFile = $this->root . '/.env.injection-test';

    $result = Env::mergeIntoEnvFile($envFile, [
        'INJECTED' => "value\nEVIL_KEY=evil\r\0value",
    ]);

    expect($result)->toBeTrue()
        ->and(file_get_contents($envFile))->toBe("INJECTED=valueEVIL_KEY=evilvalue\n");
});

test('mergeIntoEnvFile returns false when the target directory cannot be written to', function (): void {
    $dir = $this->root . '/locked-merge-dir';
    mkdir($dir, 0o777, true);
    chmod($dir, 0o555);
    $envFile = $dir . '/.env.locked';

    set_error_handler(static fn (): bool => true);
    try {
        $result = Env::mergeIntoEnvFile($envFile, ['KEY' => 'value']);
    } finally {
        restore_error_handler();
        chmod($dir, 0o755);
    }

    expect($result)->toBeFalse()
        ->and(file_exists($envFile))->toBeFalse();
});

test('mergeIntoEnvFile treats an unreadable existing file as having no prior lines, not crashing', function (): void {
    // Kills line 147's FalseToTrue: @file() never returns the literal
    // boolean true (only an array of lines, or false on failure), so a
    // mutated `!== true` is always true regardless of success/failure,
    // wrongly foreach()-ing over a bare `false` -- a real PHP warning
    // this project's own failOnWarning setting turns into a test
    // failure. Confirmed live via a temporary sed-applied mutation AND
    // via a real `pest --mutate --filter=EnvTest --id=...` run (both
    // show this test genuinely fails against the mutant). `pest
    // --mutate`'s OWN un-scoped/full-class scan still reports this
    // mutation as UNTESTED regardless -- root-caused to a real bug in
    // pestphp/pest-plugin-mutate's own StreamWrapper::url_stat(), which
    // gates every file:// stat (is_file()/file_exists()/is_readable())
    // through `is_readable($path) === false -> return false` for the
    // WHOLE process while ANY mutation subprocess is running, for ANY
    // path, not just the mutated file. That makes this test's own 0000
    // fixture file look like it doesn't exist at all under is_file()
    // (line 145), so real code skips the file()/foreach() branch
    // entirely and the mutation on line 147 never even executes --
    // this is a tool blindness, not equivalence: a second, distinct
    // root cause of the same proc_open() subprocess-invisibility class.
    // 0000 permissions (not merely unreadable-by-others, which this
    // sandboxed environment's own capability-based bypass tolerates for
    // some operations -- see FilesystemHelperTest.php's own documented
    // finding) reliably makes file() fail even for the owner.
    $envFile = $this->root . '/.env.unreadable';
    touch($envFile);
    chmod($envFile, 0o000);

    // @ suppression alone doesn't stop PHPUnit's own ErrorHandler from
    // surfacing file()'s own permission-denied warning -- but only THAT
    // one warning is expected from real code; returning false from a
    // custom handler falls through to PHP's own built-in reporting (a
    // silent-to-Pest print), not back to PHPUnit's own handler, so a
    // blanket "return true" here would also hide the very
    // foreach()-on-false warning this test exists to catch under the
    // mutant -- collect and assert on it explicitly instead.
    $unexpectedWarnings = [];
    set_error_handler(static function (int $errno, string $errstr) use (&$unexpectedWarnings): bool {
        if (! str_contains($errstr, 'Failed to open stream')) {
            $unexpectedWarnings[] = $errstr;
        }

        return true;
    });
    try {
        $result = Env::mergeIntoEnvFile($envFile, ['NEW_KEY' => 'value']);
    } finally {
        restore_error_handler();
        chmod($envFile, 0o644);
    }

    expect($unexpectedWarnings)->toBe([]);

    expect($result)->toBeTrue()
        ->and(file_get_contents($envFile))->toBe("NEW_KEY=value\n");
});

test('mergeIntoEnvFile drops an existing line whose key extraction genuinely fails', function (): void {
    // Kills line 149's FalseToTrue: strtok($line, '=') only ever
    // returns false for a line that's *entirely* delimiter characters
    // (a bare "=" or "=="), not merely a line missing '=' altogether
    // (strtok() then returns the whole line as one token) -- confirmed
    // live. array_key_exists(false, $values) casts the bool key the
    // same way PHP array access would, never matching a real string
    // key, so the real `!== false` check is what actually drops this
    // line; the mutated `!== true` is always true, wrongly preserving
    // it.
    $envFile = $this->root . '/.env.bare-equals';
    file_put_contents($envFile, "GOOD_KEY=value\n=\n");

    Env::mergeIntoEnvFile($envFile, ['NEW_KEY' => 'new']);

    expect(file_get_contents($envFile))->toBe("NEW_KEY=new\nGOOD_KEY=value\n");
});

/**
 * Also confirmed-equivalent (both verified live via
 * temporary sed-applied mutations against a fresh-file merge): line
 * 155's DecrementInteger/IncrementInteger (bin2hex(random_bytes(4))'s
 * own byte count -- any length still produces a working, unique-enough
 * temp filename) and its 4 concat mutants (2x ConcatRemoveLeft, 2x
 * ConcatRemoveRight) -- $tmp is used consistently for both the write
 * and the immediately-following rename() regardless of its own exact
 * shape, and rename() itself transparently handles moving a
 * relative-path source to an absolute-path destination (confirmed live:
 * even fully dropping $envFile from $tmp's own construction, forcing a
 * CWD-relative working path, still produced byte-identical final
 * output); and line 156's FalseToTrue on `file_put_contents(...) ===
 * false` -- file_put_contents() never returns the literal boolean true,
 * so the mutated `=== true` is always false, collapsing the whole
 * condition down to just `! rename(...)` alone, which fails
 * identically whenever the write itself failed (a non-existent $tmp
 * can't be renamed either), converging to the same final return value
 * either way.
 */
