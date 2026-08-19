<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * No prior LoggerTest.php existed -- everything below targets the
 * specific still-red lines from the coverage report (this class's
 * indirect callers, e.g. LoungeMaintenance's own purge() usage, already
 * cover the rest of write()/purge()/log()'s happy paths and debug()/
 * info()/error()/levelToCode()'s EMERGENCY/INFO/DEBUG/ERROR/default arms
 * and codeToLevel()'s DEBUG/default arms).
 *
 * Three scenarios below need a real PHP-level warning to be raised as
 * part of exercising a branch the class itself already handles
 * gracefully (fopen()/glob()/fwrite() failures) -- a bare `@` does NOT
 * stop PHPUnit's ErrorHandler from converting it into a thrown Warning
 * (confirmed live), so each wraps only its one risky call with a no-op
 * set_error_handler()/restore_error_handler() pair, matching the
 * established pattern in tests/Unit/Admin/Image/ImageGdTest.php.
 */
function loggerTestRrmdir(string $dir): void
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
            loggerTestRrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir() . '/piwigo-logger-test-' . bin2hex(random_bytes(8));
    mkdir($this->root, 0o777, true);
});

afterEach(function (): void {
    loggerTestRrmdir($this->root);
});

test('accepts severity as a string code, converting it to the matching level constant', function (): void {
    // Kills line 112's CoalesceRemoveLeft (`is_string(null)` instead of
    // `is_string($this->options['severity'] ?? null)`): always false
    // regardless of what was actually passed, so codeToLevel() never
    // runs and the string 'ERROR' is left as-is. severity()'s own
    // is_int() guard then falls back to the most permissive threshold
    // (DEBUG) instead of the configured one -- confirmed live: real
    // code correctly filters out the info() call, the mutant lets it
    // through too.
    $dir = $this->root . '/string-severity-dir';
    $logger = new Logger([
        'directory' => $dir,
        'filename' => 'x.txt',
        'severity' => 'ERROR',
    ]);

    $logger->info('should not appear');
    $logger->error('should appear');

    $content = file_get_contents($dir . '/x.txt');
    expect($content)
        ->not->toBeFalse();
    assert(is_string($content));

    expect($content)
        ->not->toContain('should not appear')
        ->toContain('should appear');
});

test('constructs a default log_YYYY-MM-DD.txt filename when none is given, creating the directory on first write', function (): void {
    $dir = $this->root . '/fresh-dir';

    $logger = new Logger([
        'directory' => $dir,
    ]);
    $logger->write('hello there');

    $expectedFile = $dir . '/log_' . date('Y-m-d') . '.txt';

    expect(is_dir($dir))
        ->toBeTrue()
        ->and(file_exists($expectedFile))
        ->toBeTrue()
        ->and(file_get_contents($expectedFile))
        ->toBe('hello there');
});

test('creates a nested log directory recursively, protected with .htaccess and index.htm', function (): void {
    // Kills line 150's BooleanAndToBooleanOr-on-bitmask mutation
    // (`MKGETDIR_DEFAULT & MKGETDIR_PROTECT_HTACCESS` instead of `|`):
    // ANDing these two non-overlapping bitmasks together yields 0 (no
    // flags at all) instead of all four flags -- confirmed live this
    // makes mkgetdir() neither create the directory recursively (so a
    // multi-level-deep, not-yet-existing directory fails outright) nor
    // add the .htaccess/index.htm protection files a single-level
    // "constructs a default filename" test above never checks for.
    $dir = $this->root . '/a/b/c';

    $logger = new Logger([
        'directory' => $dir,
        'filename' => 'nested.txt',
    ]);
    $logger->write('deep');

    expect(is_dir($dir))
        ->toBeTrue()
        ->and(file_exists($dir . '/.htaccess'))->toBeTrue()
        ->and(file_exists($dir . '/index.htm'))->toBeTrue()
        ->and(file_get_contents($dir . '/nested.txt'))->toBe('deep');
});

/**
 * Confirmed-equivalent: line 124's UnwrapRtrim (dropping the
 * `rtrim(..., '\/')` call, storing the directory with any trailing
 * slash it was given intact rather than stripped before
 * DIRECTORY_SEPARATOR is appended). Confirmed live via Reflection on a
 * constructed logger's own $options['filePath']: without the rtrim(),
 * a directory already ending in '/' produces a literal double slash
 * ('/some/path//file.txt' instead of '/some/path/file.txt') in the
 * stored path string -- but every filesystem function this class
 * calls (is_dir(), fopen(), file_exists()) treats a doubled path
 * separator identically to a single one, so the file still gets
 * created and read back correctly either way. No behavioral test can
 * observe the difference; only inspecting the raw stored string could,
 * which would be testing an implementation detail, not behavior.
 */
test('treats a missing directory option as empty, not as a literal option value, and fails accordingly', function (): void {
    // Kills line 124's EmptyStringToNotEmpty: without the real '' fallback,
    // a non-empty placeholder would be used as the directory instead,
    // producing a working (non-root, permission-granted) relative path
    // instead of the real fallback's absolute filesystem root -- which
    // this non-root process can never write to. Confirmed live: the real
    // fallback throws "could not be opened", the mutant doesn't throw at
    // all.
    $logger = new Logger([]);

    set_error_handler(static fn (): bool => true);
    try {
        expect(fn () => $logger->write('unreachable'))
            ->toThrow(RuntimeException::class, 'The file could not be opened. Check permissions.');
    } finally {
        restore_error_handler();
    }
});

test('treats an explicitly empty filename the same as an omitted one, generating the default name', function (): void {
    // Kills line 127's EmptyStringToNotEmpty: without the real ''
    // comparison, an explicitly empty (but non-null) filename would
    // skip the auto-generated-name fallback entirely, leaving $filename
    // as '' -- fopen()'ing a bare directory path instead of a real file
    // inside it, which throws instead of succeeding. Confirmed live.
    $dir = $this->root . '/empty-filename-dir';

    $logger = new Logger([
        'directory' => $dir,
        'filename' => '',
    ]);
    $logger->write('hi');

    $expectedFile = $dir . '/log_' . date('Y-m-d') . '.txt';
    expect(file_exists($expectedFile))
        ->toBeTrue()
        ->and(file_get_contents($expectedFile))
        ->toBe('hi');
});

test('reads a configured archiveDays and purges on construction when the random check happens to hit', function (): void {
    // Kills line 131's CoalesceRemoveLeft (dropping the read of
    // $this->options['archiveDays'], always using ARCHIVE_NO_PURGE
    // instead) and line 132's ArithmeticOperator (`mt_rand() * 97 ===
    // 0` instead of `%`): seed 115 is a real, pre-searched mt_srand()
    // seed whose very next mt_rand() call returns 421105033, a genuine
    // (non-zero) multiple of 97 -- true under the real `%` check,
    // false under the mutated `*` check (421105033 * 97 is nowhere
    // near 0). Confirmed live: with this exact seed and a non-default
    // archiveDays, real code purges an old file on construction; either
    // mutation leaves it untouched.
    $dir = $this->root . '/seeded-purge-dir';
    mkdir($dir, 0o777, true);
    $oldFile = $dir . '/log_old.txt';
    file_put_contents($oldFile, 'old');
    touch($oldFile, time() - (10 * 86400));

    mt_srand(115);
    new Logger([
        'directory' => $dir,
        'filename' => 'unused.txt',
        'archiveDays' => 1,
    ]);

    expect(file_exists($oldFile))
        ->toBeFalse();
});

test('does not purge on construction when archiveDays is configured but the random check misses', function (): void {
    // Kills line 132's BooleanAndToBooleanOr (`||` instead of `&&`):
    // seed 1 is a real, pre-searched mt_srand() seed whose next
    // mt_rand() call returns 895547922, which is NOT a multiple of 97
    // (895547922 % 97 === 78). With a non-default archiveDays (the
    // first condition true) but this seed (the second condition
    // false), real code's `&&` correctly skips the purge; the `||`
    // mutant wrongly runs it anyway since the first condition alone is
    // enough. The seed-115 test above can't catch this: both its
    // conditions are true, so `&&` and `||` agree.
    $dir = $this->root . '/seeded-no-purge-dir';
    mkdir($dir, 0o777, true);
    $oldFile = $dir . '/log_old.txt';
    file_put_contents($oldFile, 'old');
    touch($oldFile, time() - (10 * 86400));

    mt_srand(1);
    new Logger([
        'directory' => $dir,
        'filename' => 'unused.txt',
        'archiveDays' => 1,
    ]);

    expect(file_exists($oldFile))
        ->toBeTrue();
});

test('throws writefail when the target file already exists but has lost its write permission', function (): void {
    $dir = $this->root . '/existing-readonly';
    mkdir($dir, 0o777, true);
    $filename = 'readonly.txt';
    file_put_contents($dir . '/' . $filename, 'old content');
    chmod($dir . '/' . $filename, 0o444);

    $logger = new Logger([
        'directory' => $dir,
        'filename' => $filename,
    ]);

    expect(fn () => $logger->write('new content'))
        ->toThrow(RuntimeException::class, 'The file could not be written to. Check that appropriate permissions have been set.');
});

/**
 * Confirmed-equivalent: line 151's EmptyStringToNotEmpty in open()
 * (`$filePath = is_string($filePath) ? $filePath : 'PEST Mutator...'`).
 * 'filePath' is only ever unset (triggering this fallback) when the
 * constructor returned before line 129's own `$this->options['filePath']
 * = $this->options['directory'] . $this->options['filename'];`
 * assignment -- and that only happens when 'directory' is ALSO still
 * unset, which line 144's own fallback (tested above) and the
 * subsequent mkgetdir() call on an effectively-empty directory already
 * throw on, before execution can ever reach line 151. No test can
 * observe this fallback's own placeholder value; it's provably dead by
 * the time any caller could reach it.
 */
test('write() on a severity-OFF logger still normalizes a never-set directory to empty, not to a placeholder', function (): void {
    // Kills line 144's EmptyStringToNotEmpty: severity OFF makes the
    // constructor return before ever normalizing 'directory' to a real
    // string (see Logger's own constructor), so open()'s own is_string()
    // fallback is the ONLY thing standing between a genuinely-unset
    // 'directory' and a crash. write() is public and can still be
    // called directly on an OFF logger (only log()/debug()/etc. respect
    // the severity gate) -- confirmed live real code throws a
    // RuntimeException (FilesystemHelper's own "no write access"
    // message, since mkgetdir() can't create a directory at the empty
    // path), while the mutant throws a ValueError ("Path must not be
    // empty") instead -- a different exception hierarchy entirely, so
    // asserting RuntimeException specifically distinguishes them.
    $logger = new Logger([
        'severity' => Logger::OFF,
    ]);

    expect(fn () => $logger->write('bypasses the severity gate'))
        ->toThrow(RuntimeException::class);
});

test('throws openfail when fopen cannot create the target file inside a locked directory', function (): void {
    $dir = $this->root . '/locked-dir';
    mkdir($dir, 0o777, true);
    chmod($dir, 0o555);

    $logger = new Logger([
        'directory' => $dir,
        'filename' => 'unwritable.txt',
    ]);

    set_error_handler(static fn (): bool => true);
    try {
        expect(fn () => $logger->write('anything'))
            ->toThrow(RuntimeException::class, 'The file could not be opened. Check permissions.');
    } finally {
        restore_error_handler();
        chmod($dir, 0o755);
    }
});

test('open() throws when the container returns an unexpected type for CurrentConfig', function (): void {
    // Real gap: currentConfig()'s own InstanceOfToTrue guard is only
    // reached (Kernel::isBooted()) from open()'s mkgetdir() call, for a
    // directory that doesn't exist yet -- KernelContainerOverride's own
    // Kernel::boot()/reset() dance around the callback satisfies that.
    $dir = $this->root . '/current-config-guard-dir';

    KernelContainerOverride::withWrongTypeFor(
        CurrentConfig::class,
        static function () use ($dir): void {
            $logger = new Logger([
                'directory' => $dir,
                'filename' => 'guard.txt',
            ]);
            $logger->write('unreachable');
        },
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CurrentConfig::class);

test('open() uses the container-shared CurrentConfig, not a disconnected fresh one, once Kernel is booted', function (): void {
    // Real gap: kills currentConfig()'s own RemoveEarlyReturn. A fresh,
    // unmemoized CurrentConfig() (the not-booted fallback, what the
    // mutant would fall through to even with Kernel booted) defaults
    // chmodValue to 0755 under the CLI SAPI these tests run under --
    // setting the container-shared instance's own chmodValue to
    // something else and observing it on the real, newly-created
    // directory's own permissions proves currentConfig() actually
    // returned THAT instance, not a disconnected new one.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentConfigTestFactory::get()->chmodValue = 0o700;

    try {
        $dir = $this->root . '/current-config-identity-dir';
        $logger = new Logger([
            'directory' => $dir,
            'filename' => 'identity.txt',
        ]);
        $logger->write('anything');

        expect(fileperms($dir) & 0o777)
            ->toBe(0o700);
    } finally {
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
    }
});

/**
 * Confirmed-equivalent: line 177's RemoveBooleanCast in __destruct()
 * (`if ($this->fileHandle)` instead of `if ((bool) $this->fileHandle)`).
 * $this->fileHandle is typed `resource|null`, and PHP's own `if`
 * coercion already treats a resource as truthy and null as falsy
 * identically to an explicit (bool) cast -- there is no value of this
 * property for which the two could ever disagree.
 */
test('closes the underlying file handle on destruction after a successful write', function (): void {
    $dir = $this->root . '/destruct-dir';
    $logger = new Logger([
        'directory' => $dir,
        'filename' => 'destruct.txt',
    ]);
    $logger->write('line');

    $prop = new ReflectionProperty(Logger::class, 'fileHandle');
    $handle = $prop->getValue($logger);
    expect(is_resource($handle))
        ->toBeTrue();

    unset($logger);

    expect(is_resource($handle))
        ->toBeFalse();
});

test('notice/warn/alert/critical/emergency each write a line tagged with their own level code and message', function (): void {
    $dir = $this->root . '/levels-dir';
    $logger = new Logger([
        'directory' => $dir,
        'filename' => 'levels.txt',
    ]);

    $logger->notice('storage nearing capacity', 'storage');
    $logger->warn('slow query detected', 'db');
    $logger->alert('cache backend unreachable', 'cache');
    $logger->critical('payment webhook failed', 'billing');
    $logger->emergency('disk write failures across all mounts', 'disk');

    $contents = file_get_contents($dir . '/levels.txt');

    expect($contents)
        ->toContain("[NOTICE]\t[storage]\tstorage nearing capacity")
        ->toContain("[WARNING]\t[db]\tslow query detected")
        ->toContain("[ALERT]\t[cache]\tcache backend unreachable")
        ->toContain("[CRITICAL]\t[billing]\tpayment webhook failed")
        ->toContain("[EMERGENCY]\t[disk]\tdisk write failures across all mounts");
});

test('formatMessage builds the line with a leading bracket, a newline-prefixed indented context, and a trailing newline', function (): void {
    // Kills 4 mutations in formatMessage(), each dropping one piece of
    // the line's own concatenation and each independently confirmed
    // live via a sed-applied mutation rerun:
    // - line 395's ConcatRemoveLeft: without the "\n" prefix, the
    //   context glues directly onto the message text instead of
    //   starting on its own line.
    // - line 398's IfNegated: with PageState reset (executionUuid ===
    //   ''), real code falls back to the literal string 'unkonwn'; the
    //   mutant inverts the ternary and uses the empty string directly,
    //   producing "[exec=]" instead of "[exec=unkonwn]".
    // - line 399's ConcatRemoveLeft: without the leading '[' . before
    //   the timestamp, the line starts with the raw timestamp digits
    //   instead of an opening bracket.
    // - line 403's ConcatRemoveRight: without the trailing "\n", this
    //   line's own content runs directly into whatever the next log
    //   call writes, with no separator.
    $dir = $this->root . '/format-message-dir';
    $logger = new Logger([
        'directory' => $dir,
        'filename' => 'format.txt',
    ]);

    $logger->info('test message', 'cat1', [
        'key1' => 'value1',
    ]);

    $content = file_get_contents($dir . '/format.txt');
    expect($content)
        ->not->toBeFalse();
    assert(is_string($content));

    expect(str_starts_with($content, '['))
        ->toBeTrue()
        ->and(str_contains($content, '[exec=unkonwn]'))
        ->toBeTrue()
        ->and(str_contains($content, "test message\n  key1: 'value1'"))
        ->toBeTrue()
        ->and(str_ends_with($content, "\n"))
        ->toBeTrue();
});

test('pageState() throws when the container returns an unexpected type for PageState', function (): void {
    // Kills line 404's InstanceOfToTrue (`!true` instead of
    // `!$pageState instanceof PageState`): the mutant's guard can never
    // fire regardless of what the container actually resolved, silently
    // returning the wrong-typed value instead of throwing. The real
    // container, correctly wired, never legitimately resolves
    // PageState::class to anything but a PageState -- this branch is
    // otherwise unreachable through the public API.
    // KernelContainerOverride rebinds PageState::class to a plain
    // stdClass (see its own docblock), matching the same pattern used
    // throughout tests/Unit/Bootstrap/*AccessorTest.php for the
    // identical guard shape.
    $dir = $this->root . '/pagestate-wrong-type-dir';

    KernelContainerOverride::withWrongTypeFor(
        PageState::class,
        function () use ($dir): void {
            $logger = new Logger([
                'directory' => $dir,
                'filename' => 'unreachable.txt',
            ]);
            $logger->info('anything');
        }
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . PageState::class);

test('pageState() reuses the container-shared instance once booted, not a fresh unmemoized one', function (): void {
    // Kills line 408's RemoveEarlyReturn (dropping `return $pageState;`):
    // execution would fall out of the `if (Kernel::isBooted())` block
    // and hit the final `return new PageState();` instead, silently
    // discarding the real, container-shared instance just resolved (and
    // validated by the instanceof check right above) in favor of a
    // brand-new, empty one. Binding a container-shared PageState with a
    // distinguishing executionUuid proves which instance formatMessage()
    // actually consumed: real code echoes the marker back in the log
    // line; the mutant's fresh instance has an empty executionUuid,
    // which formatMessage()'s own '' -> 'unkonwn' fallback would replace
    // instead (confirmed by the "unkonwn" case already covered above).
    $sharedPageState = new PageState();
    $sharedPageState->executionUuid = 'shared-marker-uuid';
    $dir = $this->root . '/pagestate-shared-dir';

    KernelContainerOverride::with(
        [
            PageState::class => $sharedPageState,
        ],
        function () use ($dir): void {
            $logger = new Logger([
                'directory' => $dir,
                'filename' => 'shared.txt',
            ]);
            $logger->info('hello');

            $content = file_get_contents($dir . '/shared.txt');
            expect($content)
                ->not->toBeFalse();
            assert(is_string($content));
            expect($content)
                ->toContain('[exec=shared-marker-uuid]');
        }
    );
});

test('getTimestamp computes a real, current sub-second microsecond value, not a garbage one', function (): void {
    // Kills line 466's ArithmeticOperator (`+ floor()` instead of `-
    // floor()`, producing a value roughly double the real microtime()
    // and wildly out of the [0, 999999] microsecond window once scaled
    // and truncated), line 416's ConcatRemoveRight (dropping the actual
    // computed $micro digits from the date() string entirely, always
    // producing 000000), line 418's CoalesceRemoveLeft (always using
    // the default 'Y-m-d G:i:s' format regardless of the configured
    // one, so the 'u'-only format below would show the FULL default
    // date string instead of 6 digits), and line 419's IfNegated (same
    // observable effect as 418 for a string dateFormat -- takes the
    // "not a string" branch instead, wrongly falling back to the
    // default format even though 'u' genuinely is a string).
    //
    // dateFormat 'u' isolates just the microsecond component in the
    // log output. Bracketing the log call with real microtime(true)
    // calls and asserting the logged value falls within a generous
    // (but far tighter than any of these mutations produce) tolerance
    // proves it's a real, current microsecond value.
    //
    // Also confirmed equivalent/untestable while investigating this
    // line: line 466's RemoveIntegerCast (dropping the `(int)` before
    // sprintf('%06d', ...)) is PROVABLY equivalent -- sprintf()'s own
    // %d conversion already truncates a float exactly like an (int)
    // cast would (confirmed directly: sprintf('%06d', 999999.9) and
    // sprintf('%06d', (int) 999999.9) both give '999999'). Line 415's
    // DecrementFloat/IncrementFloat (scaling by 999999.0/1000001.0
    // instead of 1000000.0) genuinely DO change the computed value,
    // but by at most ~1 microsecond out of a 0-999999 range -- measured
    // live across 5 runs of the actual mutant, the resulting diff
    // (127-363) fell squarely inside the SAME range this test's own
    // real-code tolerance already has to accommodate for ordinary
    // execution jitter (129-556 microseconds, measured the same way).
    // No wall-clock-based test can reliably distinguish a ~1-unit
    // systematic bias from that much natural noise without mocking the
    // clock, which isn't available for this private method's own bare
    // microtime() call.
    // Also closes a FloorToRound mutation on this same line
    // (`round($originalTime)` instead of `floor($originalTime)`,
    // extracting the whole-seconds part before subtracting it back
    // out): floor() and round() only disagree when the fractional part
    // is >= 0.5 -- confirmed live that when they do, the mutant
    // produces a negative $micro whose sprintf('%06d', ...) output
    // either fails the digits-only regex below or, worse, crashes
    // getTimestamp() outright with a DateMalformedStringException.
    // Purely real-clock-driven, this only has ~50% odds of showing up
    // on any single call, so the loop below keeps retrying (a handful
    // of iterations, practically always well under the cap) until it
    // genuinely observes that fractional-part window before making its
    // assertions -- otherwise this test would only catch the mutation
    // about half the time it happens to run.
    $dir = $this->root . '/timestamp-dir';
    $logger = new Logger([
        'directory' => $dir,
        'filename' => 'ts.txt',
        'dateFormat' => 'u',
    ]);

    // Targets [0.5, 0.9) specifically, not the full [0.5, 1.0): a
    // handful of microseconds of real work happen between this loop
    // exiting and getTimestamp()'s own internal microtime() call, and
    // without this margin, a fraction observed right near the 1.0
    // rollover can cross into the next second (fraction back near 0)
    // before that internal call runs, silently defeating the retry
    // (confirmed live: without this margin, the test intermittently
    // passed even against the actual FloorToRound mutant).
    // usleep(), not a tight spin: a bare busy-loop's own iterations are
    // fast enough that hundreds of them can complete within the SAME
    // microsecond, never actually advancing the wall clock far enough
    // to reach the target window -- confirmed live (a 400-iteration
    // spin cap was reached with the fraction barely having moved at
    // all). Small real sleeps between checks let wall-clock time
    // actually pass.
    $attempts = 0;
    do {
        $before = microtime(true);
        $fraction = fmod($before, 1);
        $attempts++;
        if ($fraction < 0.5 || $fraction >= 0.9) {
            usleep(10_000);
        }
    } while (($fraction < 0.5 || $fraction >= 0.9) && $attempts < 150);

    $logger->info('x');
    $after = microtime(true);

    $content = file_get_contents($dir . '/ts.txt');
    expect($content)
        ->not->toBeFalse();
    assert(is_string($content));

    preg_match('/\[(.*?)\]/', $content, $matches);
    $loggedRaw = $matches[1] ?? '';
    expect($loggedRaw)
        ->toMatch('/^\d{6}$/');

    $logged = (int) $loggedRaw;
    $afterMicro = (int) round(fmod($after, 1) * 1_000_000);
    // 50ms tolerance: real drift between capture and file write is
    // consistently under 1ms in this environment; any of the mutations
    // above produce a difference of hundreds of thousands of
    // microseconds or an entirely wrong-shaped string.
    expect(abs($logged - $afterMicro))
        ->toBeLessThan(50_000);
});

test('contextToString formats every case var_export() can produce: multiple keys, nested arrays, an empty array, and escaped characters', function (): void {
    // Kills 10 mutations across contextToString(), each independently
    // confirmed live via a sed-applied mutation rerun -- a single key
    // with a plain scalar value can't distinguish most of these, since
    // "append" and "overwrite" (or a dropped indentation/collapse
    // step) look identical with only one loop iteration or only
    // top-level values:
    // - line 431's EmptyStringToNotEmpty ($export's own initial value):
    //   a leading garbage prefix would appear before the first entry.
    // - line 433's ConcatEqualToEqual ($export .= $key . ': ' becoming
    //   an overwrite): only the LAST context entry would survive.
    // - line 434's ConcatEqualToEqual (same mutation on the value
    //   append): each entry's OWN "key: " prefix would be immediately
    //   overwritten by its own value.
    // - lines 436/437's RemoveArrayItem (dropping the "=>\s+letter" or
    //   "array(\s+)" collapse patterns): nested/empty arrays would keep
    //   var_export()'s own multi-line "=> \n  array (" / "array (\n)"
    //   shape instead of collapsing onto one line.
    // - lines 441/442's RemoveArrayItem (dropping the matching
    //   replacement text, desyncing the whole pattern/replacement
    //   array pairing): garbles the SAME nested/empty-array output in
    //   a different, still-observable way.
    // - line 445's UnwrapStrReplace (dropping the 'array (' -> 'array('
    //   normalization entirely): nested arrays would show "array ("
    //   with the space PHP's own var_export() always includes.
    // - line 449's ArrayItemRemoval (dropping the '\\\\' -> '\\'
    //   unescape pair): a literal backslash in a value would stay
    //   double-escaped as var_export() writes it, instead of collapsing
    //   to the single real backslash the source string contains.
    // - line 461's UnwrapStrReplace (indent()'s own "\n" -> "\n$indent"
    //   becoming "\n" -> "$indent", dropping the newline): consecutive
    //   context entries would run together on one line instead of each
    //   getting its own indented line.
    //
    // Line 489's own RemoveArrayItem (the third pattern/replacement
    // pair, '/^  |\G  /m' -> '  ') is NOT closable by any test: it's a
    // pattern that matches a literal 2-space run and replaces it with
    // the identical 2-space string, a no-op by construction regardless
    // of whether it runs at all -- confirmed live across both this rich
    // context and a deeper 3-level-nested one with zero difference
    // either way.
    $dir = $this->root . '/context-dir';
    $logger = new Logger([
        'directory' => $dir,
        'filename' => 'context.txt',
    ]);

    $logger->info('msg', 'cat', [
        'first' => 'one',
        'second' => 'two',
        'nested' => [
            'inner' => 'val',
        ],
        'empty' => [],
        'backslash' => 'a\\b',
        'quote' => "it's",
    ]);

    $content = file_get_contents($dir . '/context.txt');
    expect($content)
        ->not->toBeFalse();
    assert(is_string($content));

    expect($content)
        ->toContain("\n  first: 'one'\n")
        ->toContain("\n  second: 'two'\n")
        ->toContain("\n  nested: array(\n    'inner' => 'val',\n  )\n")
        ->toContain("\n  empty: array()\n")
        ->toContain("\n  backslash: 'a\\b'\n")
        ->toContain("\n  quote: 'it's'")
        // Kills line 449's UnwrapRtrim (dropping rtrim() before the
        // backslash/quote unescaping str_replace()): without it, the
        // context's own trailing PHP_EOL survives, then gets indented
        // by indent() into a stray blank "  " line before the final
        // newline formatMessage() itself appends -- confirmed live.
        ->and(str_ends_with($content, "quote: 'it's'\n"))
        ->toBeTrue();
});

test('levelToCode includes the actual unrecognized level in its exception message, not just the level constant name', function (): void {
    // Kills line 480's UnwrapConcat (dropping the 'Unknown severity
    // level ' prefix, throwing with just the bare level number instead).
    expect(fn (): string => Logger::levelToCode(999))
        ->toThrow(RuntimeException::class, 'Unknown severity level 999');
});

test('throws writefail when fwrite itself fails on an already-open handle (e.g. a full device)', function (): void {
    // /dev/full is a standard Linux character device that accepts opens
    // but fails every write with ENOSPC -- a real, portable way to make
    // fwrite() legitimately return false without touching filesystem
    // permissions (which open()'s own is_writable() check would catch
    // earlier instead).
    $logger = new Logger([
        'directory' => '/dev',
        'filename' => 'full',
    ]);

    set_error_handler(static fn (): bool => true);
    try {
        expect(fn () => $logger->write('this will not fit'))
            ->toThrow(RuntimeException::class, 'The file could not be written to. Check that appropriate permissions have been set.');
    } finally {
        restore_error_handler();
    }
});

test('purge returns without touching anything when glob() itself fails (e.g. an over-long directory path)', function (): void {
    // A glob() pattern beyond PATH_MAX (4096 bytes on Linux) makes glob()
    // return false rather than an empty array -- a real, deterministic
    // failure mode distinct from "no files matched". The constructor
    // itself has a 1-in-97 random chance of also calling purge() (see its
    // own archiveDays handling), so both the construction and the
    // explicit call below are wrapped -- otherwise this test would be
    // flaky, rarely tripping the same warning before the handler is
    // installed.
    //
    // Kills line 368's IdenticalToTrue (`$files === true` instead of
    // `=== false`): real code returns immediately on glob() failure;
    // the mutant's check is always false (glob() never returns literal
    // true), so it wrongly falls through to `foreach ($files as ...)`
    // on a bare `false` -- a real second PHP warning, confirmed live.
    // A blanket suppressing handler would hide that second warning too,
    // so only "glob(): Pattern exceeds..." is treated as expected here;
    // anything else gets collected and asserted empty.
    $dir = $this->root . '/' . str_repeat('a', 4200);

    $unexpectedWarnings = [];
    set_error_handler(static function (int $errno, string $errstr) use (&$unexpectedWarnings): bool {
        if (! str_contains($errstr, 'Pattern exceeds the maximum allowed length')) {
            $unexpectedWarnings[] = $errstr;
        }

        return true;
    });
    try {
        $logger = new Logger([
            'directory' => $dir,
            'filename' => 'irrelevant.txt',
            'archiveDays' => 1,
        ]);
        $logger->purge();
    } finally {
        restore_error_handler();
    }

    expect($unexpectedWarnings)
        ->toBe([]);
});

test('purge deletes only files older than the archive window, matching the configured glob pattern', function (): void {
    $dir = $this->root . '/purge-dir';
    mkdir($dir, 0o777, true);

    $oldFile = $dir . '/log_old.txt';
    $recentFile = $dir . '/log_recent.txt';
    $otherExtension = $dir . '/keepme.dat';
    file_put_contents($oldFile, 'old');
    file_put_contents($recentFile, 'recent');
    file_put_contents($otherExtension, 'not a log file');

    touch($oldFile, time() - (10 * 86400));
    touch($recentFile, time() - 60);
    touch($otherExtension, time() - (10 * 86400));

    $logger = new Logger([
        'directory' => $dir,
        'filename' => 'unused.txt',
        'globPattern' => 'log_*.txt',
        'archiveDays' => 5,
    ]);
    $logger->purge();

    expect(file_exists($oldFile))
        ->toBeFalse()
        ->and(file_exists($recentFile))
        ->toBeTrue()
        ->and(file_exists($otherExtension))
        ->toBeTrue();
});

test('purge deletes only files exactly at the archive-window boundary, keeping them, not past it', function (): void {
    // Kills line 373's IncrementInteger (86400 -> 86399, a 1-second-per-day
    // drift in the cutoff computation) and line 376's SmallerOrEqual
    // (`<=` instead of `<`): both wrongly delete a file whose mtime sits
    // EXACTLY on the archiveDays boundary, which real code -- using
    // strict `<` against the correct 86400-per-day cutoff -- keeps.
    // Confirmed live: a file 1 whole day old at the exact second
    // survives under real code, gets deleted under either mutation.
    $dir = $this->root . '/purge-boundary-dir';
    mkdir($dir, 0o777, true);

    $boundaryFile = $dir . '/log_boundary.txt';
    file_put_contents($boundaryFile, 'boundary');
    touch($boundaryFile, time() - 86400);

    $logger = new Logger([
        'directory' => $dir,
        'filename' => 'unused.txt',
        'globPattern' => 'log_*.txt',
        'archiveDays' => 1,
    ]);
    $logger->purge();

    expect(file_exists($boundaryFile))
        ->toBeTrue();
});

test('purge deletes a file exactly one second past the archive-window boundary', function (): void {
    // Kills a second, distinct IncrementInteger on line 373 (86400 ->
    // 86401, the opposite drift direction from the test above): a file
    // exactly 86401 seconds old is 1 second PAST the real 1-day cutoff
    // and should be deleted. The 86401 mutant's own cutoff is 1 second
    // MORE lenient (further in the past), so it wrongly keeps this
    // file -- confirmed live. Needed because the boundary-exact test
    // above only distinguishes a STRICTER (86399) cutoff; a LOOSER one
    // needs a file just past the real boundary instead.
    $dir = $this->root . '/purge-past-boundary-dir';
    mkdir($dir, 0o777, true);

    $pastBoundaryFile = $dir . '/log_past.txt';
    file_put_contents($pastBoundaryFile, 'past');
    touch($pastBoundaryFile, time() - 86401);

    $logger = new Logger([
        'directory' => $dir,
        'filename' => 'unused.txt',
        'globPattern' => 'log_*.txt',
        'archiveDays' => 1,
    ]);
    $logger->purge();

    expect(file_exists($pastBoundaryFile))
        ->toBeFalse();
});

/**
 * Confirmed-equivalent: line 365's EmptyStringToNotEmpty in purge()
 * (`$globPattern = is_string($globPattern) ? $globPattern :
 * 'PEST Mutator...'`). Unlike 'directory', 'globPattern' has an
 * unconditional default ('log_*.txt') in $this->options's own property
 * initializer, applied via array_merge() BEFORE the severity-OFF early
 * return -- so it is always a string by the time purge() runs,
 * regardless of severity. This fallback's own placeholder value can
 * never be observed.
 */
test('purge(), called directly on a severity-OFF logger, treats a never-normalized directory as empty rather than a placeholder', function (): void {
    // Kills line 363's EmptyStringToNotEmpty: same "constructor
    // returned before normalizing 'directory'" scenario as the write()
    // test above, this time reaching purge()'s own is_string() fallback
    // instead of open()'s. An empty directory resolves glob() relative
    // to the current working directory; a placeholder string would not
    // match anything there, leaving the file untouched. chdir() into a
    // scratch directory (restored in `finally`) makes this deterministic
    // and safe, rather than depending on the real process CWD never
    // containing a matching log_*.txt file.
    $dir = $this->root . '/off-purge-cwd';
    mkdir($dir, 0o777, true);
    $oldFile = $dir . '/log_old.txt';
    file_put_contents($oldFile, 'old');
    touch($oldFile, time() - (10 * 86400));

    $originalCwd = getcwd();
    chdir($dir);
    try {
        $logger = new Logger([
            'severity' => Logger::OFF,
            'archiveDays' => 1,
        ]);
        $logger->purge();
    } finally {
        chdir(is_string($originalCwd) ? $originalCwd : '/');
    }

    expect(file_exists($oldFile))
        ->toBeFalse();
});

test('codeToLevel converts every known severity code, case-insensitively, to its matching level constant', function (): void {
    expect(Logger::codeToLevel('emergency'))->toBe(Logger::EMERGENCY)
        ->and(Logger::codeToLevel('ALERT'))->toBe(Logger::ALERT)
        ->and(Logger::codeToLevel('Critical'))->toBe(Logger::CRITICAL)
        ->and(Logger::codeToLevel('NOTICE'))->toBe(Logger::NOTICE)
        ->and(Logger::codeToLevel('info'))->toBe(Logger::INFO)
        ->and(Logger::codeToLevel('Warning'))->toBe(Logger::WARNING)
        ->and(Logger::codeToLevel('debug'))->toBe(Logger::DEBUG)
        ->and(Logger::codeToLevel('ERROR'))->toBe(Logger::ERROR);
});

test('codeToLevel throws for an unrecognized severity code', function (): void {
    expect(fn (): int => Logger::codeToLevel('NOT_A_LEVEL'))
        ->toThrow(RuntimeException::class, 'Unknown severity code NOT_A_LEVEL');
});

test('levelToCode converts every level constant to its own string name', function (): void {
    expect(Logger::levelToCode(Logger::EMERGENCY))->toBe('EMERGENCY')
        ->and(Logger::levelToCode(Logger::ALERT))->toBe('ALERT')
        ->and(Logger::levelToCode(Logger::CRITICAL))->toBe('CRITICAL')
        ->and(Logger::levelToCode(Logger::NOTICE))->toBe('NOTICE')
        ->and(Logger::levelToCode(Logger::INFO))->toBe('INFO')
        ->and(Logger::levelToCode(Logger::WARNING))->toBe('WARNING')
        ->and(Logger::levelToCode(Logger::DEBUG))->toBe('DEBUG')
        ->and(Logger::levelToCode(Logger::ERROR))->toBe('ERROR');
});
