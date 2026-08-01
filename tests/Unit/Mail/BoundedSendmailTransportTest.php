<?php

declare(strict_types=1);

use Piwigo\Mail\BoundedSendmailTransport;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Real subprocess-based tests (no mocking of Process/Symfony Mailer) --
 * each test substitutes a small, disposable executable script for the real
 * `/usr/sbin/sendmail` binary via BoundedSendmailTransport's own
 * $sendmailPath constructor argument, matching exactly how it's really
 * configured (php.ini's sendmail_path, "<binary> <flags...>").
 */
function fakeSendmailScript(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'pwg_fake_sendmail_') . '.sh';
    file_put_contents($path, "#!/usr/bin/env bash\n" . $body);
    chmod($path, 0755);

    return $path;
}

/**
 * @return array{0: string, 1: string, 2: string} script path, argv-capture file, stdin-capture file
 */
function fakeSendmailCapturing(int $exitCode = 0): array
{
    $argvFile = tempnam(sys_get_temp_dir(), 'pwg_argv_');
    $stdinFile = tempnam(sys_get_temp_dir(), 'pwg_stdin_');
    $script = fakeSendmailScript(<<<BASH
        printf '%s\\n' "\$@" > '{$argvFile}'
        cat > '{$stdinFile}'
        exit {$exitCode}
        BASH);

    return [$script, $argvFile, $stdinFile];
}

function fakeSendmailSleeping(float $seconds): string
{
    return fakeSendmailScript("cat > /dev/null\nsleep {$seconds}\n");
}

function testEmail(string $to = 'recipient@example.test', string $subject = 'Test'): Email
{
    return new Email()
        ->from('sender@example.test')
        ->to($to)
        ->subject($subject)
        ->text("line one\nline two");
}

/**
 * @param list<string> $paths
 */
function cleanupFakeSendmail(array $paths): void
{
    foreach ($paths as $path) {
        @unlink($path);
    }
}

test('doSend invokes sendmail with -f<sender>, recipients after --, and strips -t', function (): void {
    [$script, $argvFile, $stdinFile] = fakeSendmailCapturing();

    $transport = new BoundedSendmailTransport($script . ' -t -i', 5.0);
    $transport->send(testEmail('bob@example.test'));

    $argv = trim((string) file_get_contents($argvFile));
    $lines = explode("\n", $argv);

    cleanupFakeSendmail([$script, $argvFile, $stdinFile]);

    expect($lines)->not->toContain('-t')
        ->and($lines)->toContain('-i')
        ->and($lines)->toContain('-f')
        ->and($lines)->toContain('sender@example.test')
        ->and($lines)->toContain('--')
        ->and($lines)->toContain('bob@example.test');
});

test('doSend trims leading/trailing whitespace from the configured sendmail_path before splitting it into binary + flags', function (): void {
    // Kills line 61's UnwrapTrim (`preg_split('/\s+/', $this->sendmailPath)`
    // instead of `preg_split('/\s+/', trim($this->sendmailPath))`): without
    // the trim(), a leading run of whitespace makes preg_split() emit a
    // leading empty-string element, so $binaryAndFlags[0] becomes '' and
    // doSend() throws the "sendmail_path is empty or invalid" exception
    // instead of actually sending -- asserted here via the exact argv
    // sendmail was invoked with (a throw would leave $argvFile empty and
    // this toBe() would fail).
    [$script, $argvFile, $stdinFile] = fakeSendmailCapturing();

    $transport = new BoundedSendmailTransport('  ' . $script . ' -t -i  ', 5.0);
    $transport->send(testEmail('bob@example.test'));

    $argv = trim((string) file_get_contents($argvFile));
    $lines = explode("\n", $argv);

    cleanupFakeSendmail([$script, $argvFile, $stdinFile]);

    expect($lines)->toBe(['-i', '-f', 'sender@example.test', '--', 'bob@example.test']);
});

test('doSend slices off only the sendmail binary itself (index 0) when deriving the flags list, keeping every configured flag in order', function (): void {
    // Kills line 69's DecrementInteger (array_slice(..., 0) instead of 1,
    // which would re-include the binary path itself as a bogus leading
    // "flag") and IncrementInteger (array_slice(..., 2) instead of 1,
    // which would silently drop the first real flag) and UnwrapArraySlice
    // (dropping array_slice() entirely -- equivalent to offset 0). A flag
    // that isn't '-t' is used here (alongside '-i') so the -t-stripping
    // filter on line 75 can't mask an off-by-one on the slice offset.
    [$script, $argvFile, $stdinFile] = fakeSendmailCapturing();

    $transport = new BoundedSendmailTransport($script . ' -x -i', 5.0);
    $transport->send(testEmail('bob@example.test'));

    $argv = trim((string) file_get_contents($argvFile));
    $lines = explode("\n", $argv);

    cleanupFakeSendmail([$script, $argvFile, $stdinFile]);

    expect($lines)->toBe(['-x', '-i', '-f', 'sender@example.test', '--', 'bob@example.test']);
});

/**
 * Confirmed-equivalent: line 75's UnwrapArrayValues (`array_filter($flags,
 * ...)` instead of `array_values(array_filter($flags, ...))`). array_slice()
 * on line 69 always hands array_filter() a plain 0-based sequential-int-keyed
 * array, so filtering it out only ever leaves a *subset* of those same
 * integer keys (never string keys). Every downstream use of $flags --
 * `in_array('-i', $flags, true)` / `in_array('-oi', ...)` (line 78),
 * `in_array('-f', $flags, true)` (line 81), and the `...$flags` spread
 * building $command (line 80) -- either ignores keys entirely (in_array())
 * or, for the spread, PHP's own unpacking semantics renumber int keys from 0
 * regardless of any gaps left by array_filter(), which is exactly what
 * array_values() would have done anyway. So dropping array_values() here
 * cannot change $command's resulting values or order for any input.
 * Confirmed live: reapplying this exact mutation (removing the outer
 * array_values() call) and rerunning this whole file leaves every test
 * passing unchanged, including the two argv-exact-match tests above.
 */

test('doSend pipes the full MIME message (headers + body) to sendmail\'s stdin', function (): void {
    [$script, $argvFile, $stdinFile] = fakeSendmailCapturing();

    $transport = new BoundedSendmailTransport($script . ' -t -i', 5.0);
    $transport->send(testEmail(subject: 'Hello Sendmail'));

    $stdin = (string) file_get_contents($stdinFile);

    cleanupFakeSendmail([$script, $argvFile, $stdinFile]);

    expect($stdin)->toContain('Subject: Hello Sendmail')
        ->and($stdin)->toContain('line one')
        ->and($stdin)->toContain('line two');
});

test('doSend doubles a leading dot on its own line when -i/-oi is absent (dot-stuffing)', function (): void {
    [$script, $argvFile, $stdinFile] = fakeSendmailCapturing();

    // No -i/-oi in the configured flags this time.
    $transport = new BoundedSendmailTransport($script . ' -t', 5.0);
    $transport->send(new Email()
        ->from('sender@example.test')
        ->to('bob@example.test')
        ->subject('Dot test')
        ->text("before\n.\nafter"));

    $stdin = (string) file_get_contents($stdinFile);

    cleanupFakeSendmail([$script, $argvFile, $stdinFile]);

    expect($stdin)->toContain("before\n..\nafter");
});

test('doSend leaves a leading dot alone when -i is present (no dot-stuffing)', function (): void {
    [$script, $argvFile, $stdinFile] = fakeSendmailCapturing();

    $transport = new BoundedSendmailTransport($script . ' -t -i', 5.0);
    $transport->send(new Email()
        ->from('sender@example.test')
        ->to('bob@example.test')
        ->subject('Dot test')
        ->text("before\n.\nafter"));

    $stdin = (string) file_get_contents($stdinFile);

    cleanupFakeSendmail([$script, $argvFile, $stdinFile]);

    expect($stdin)->toContain("before\n.\nafter")
        ->and($stdin)->not->toContain("before\n..\nafter");
});

test('doSend throws a TransportException (not an unbounded hang) when sendmail exceeds the configured timeout', function (): void {
    $script = fakeSendmailSleeping(5.0);
    $transport = new BoundedSendmailTransport($script, 0.3);

    $start = hrtime(true);
    expect(static fn () => $transport->send(testEmail()))
        ->toThrow(TransportException::class, 'timed out');
    $elapsedSeconds = (hrtime(true) - $start) / 1_000_000_000;

    cleanupFakeSendmail([$script]);

    // The whole point of this class: bounded by ~$timeoutSeconds, never
    // anywhere near the fake sendmail's real 5-second sleep.
    expect($elapsedSeconds)->toBeLessThan(4.0);
});

test('doSend\'s timeout exception message concatenates the prefix, the configured timeout, "s: ", and the wrapped exception\'s own message in that exact order, and preserves exit code 0', function (): void {
    // Kills line 104's 3x ConcatRemoveRight / 3x ConcatSwitchSides (on
    // 'Sendmail process timed out after ' . $this->timeoutSeconds . 's: '
    // . $e->getMessage()) and DecrementInteger/IncrementInteger on the
    // hardcoded exception code (0). The expected string is rebuilt here
    // from the exact same live ingredients ($timeoutSeconds and the real
    // ProcessTimedOutException's own getMessage(), fetched via
    // getPrevious()) rather than hardcoding Symfony's own wording, so this
    // stays exact (toBe(), not toContain()) without coupling to
    // Process::getCommandLine()'s formatting.
    $script = fakeSendmailSleeping(5.0);
    $timeoutSeconds = 0.3;
    $transport = new BoundedSendmailTransport($script, $timeoutSeconds);

    $threw = false;
    try {
        $transport->send(testEmail());
    } catch (TransportException $e) {
        $threw = true;
        // getPrevious() is asserted non-null via toBeInstanceOf() first so
        // a regression here (e.g. the previous exception silently stops
        // being passed) fails loudly instead of the nullsafe fallback
        // below quietly turning it into a passing empty-suffix comparison.
        expect($e->getPrevious())->toBeInstanceOf(ProcessTimedOutException::class);
        expect($e->getMessage())->toBe('Sendmail process timed out after ' . $timeoutSeconds . 's: ' . ($e->getPrevious()?->getMessage() ?? ''));
        expect($e->getCode())->toBe(0);
    }

    cleanupFakeSendmail([$script]);

    expect($threw)->toBeTrue();
});

test('doSend throws a TransportException (via the non-timeout ProcessExceptionInterface branch) when sendmail is killed by a signal instead of exiting normally', function (): void {
    // Process::wait() throws Symfony\Component\Process\Exception\
    // ProcessSignaledException -- a RuntimeException that implements
    // ExceptionInterface but is NOT a ProcessTimedOutException -- when the
    // child is terminated by an uncaught signal rather than exiting.
    // doSend()'s own second catch(ProcessExceptionInterface) block (as
    // opposed to the first catch(ProcessTimedOutException) block the
    // "exceeds the configured timeout" test above already covers) exists
    // for exactly this kind of failure: a real MTA process crashing/
    // getting killed mid-send, not just running too long. `kill -9 $$`
    // inside the fake script deterministically reproduces that without any
    // OS resource-limit trickery (confirmed via a standalone probe script).
    $script = fakeSendmailScript("cat > /dev/null\nkill -9 \$\$\n");

    $transport = new BoundedSendmailTransport($script, 5.0);

    expect(static fn () => $transport->send(testEmail()))
        ->toThrow(TransportException::class, 'Sendmail process could not be started');

    cleanupFakeSendmail([$script]);
});

test('doSend\'s "could not be started" exception message concatenates the prefix and the wrapped exception\'s own message in that exact order, and preserves exit code 0', function (): void {
    // Kills line 111's ConcatRemoveRight / ConcatSwitchSides (on
    // 'Sendmail process could not be started: ' . $e->getMessage()) and
    // DecrementInteger/IncrementInteger on the hardcoded exception code
    // (0). As with the timeout-message test above, the expected string is
    // rebuilt from the real ProcessSignaledException's own getMessage()
    // rather than hardcoding it, keeping the comparison exact (toBe()).
    $script = fakeSendmailScript("cat > /dev/null\nkill -9 \$\$\n");
    $transport = new BoundedSendmailTransport($script, 5.0);

    $threw = false;
    try {
        $transport->send(testEmail());
    } catch (TransportException $e) {
        $threw = true;
        expect($e->getPrevious())->toBeInstanceOf(ProcessSignaledException::class);
        expect($e->getMessage())->toBe('Sendmail process could not be started: ' . ($e->getPrevious()?->getMessage() ?? ''));
        expect($e->getCode())->toBe(0);
    }

    cleanupFakeSendmail([$script]);

    expect($threw)->toBeTrue();
});

test('doSend throws a TransportException when sendmail exits non-zero', function (): void {
    [$script, $argvFile, $stdinFile] = fakeSendmailCapturing(exitCode: 1);

    $transport = new BoundedSendmailTransport($script . ' -t -i', 5.0);

    expect(static fn () => $transport->send(testEmail()))
        ->toThrow(TransportException::class, 'exit code 1');

    cleanupFakeSendmail([$script, $argvFile, $stdinFile]);
});

test('doSend\'s "process failed" exception message concatenates the exact exit code and the exact captured stderr, not just an "exit code N" substring', function (): void {
    // Kills line 115's remaining ConcatRemoveRight/ConcatSwitchSides pair
    // on the ': ' . $process->getErrorOutput() half of the concatenation
    // (the existing "exits non-zero" test above already kills the pair on
    // the exit-code half, since a mutant there loses the digit from the
    // 'exit code 1' substring it checks). A non-empty, distinctive stderr
    // string is required here: with an empty getErrorOutput() (as in the
    // existing test), ConcatRemoveRight on the trailing
    // errorOutput concatenation would be indistinguishable from the
    // unmutated code.
    $script = fakeSendmailScript("cat > /dev/null\nprintf '%s' 'boom disk full' 1>&2\nexit 7\n");
    $transport = new BoundedSendmailTransport($script, 5.0);

    $threw = false;
    try {
        $transport->send(testEmail());
    } catch (TransportException $e) {
        $threw = true;
        expect($e->getMessage())->toBe('Sendmail process failed with exit code 7: boom disk full');
    }

    cleanupFakeSendmail([$script]);

    expect($threw)->toBeTrue();
});

test('doSend throws a TransportException for an empty sendmail_path instead of silently doing nothing', function (): void {
    $transport = new BoundedSendmailTransport('   ', 5.0);

    expect(static fn () => $transport->send(testEmail()))
        ->toThrow(TransportException::class, 'sendmail_path');
});

test('doSend\'s "sendmail_path is empty or invalid" exception message concatenates the prefix, the raw configured path, and a closing quote in that exact order', function (): void {
    // Kills line 63's 2x ConcatRemoveRight / 2x ConcatSwitchSides (on
    // 'BoundedSendmailTransport: sendmail_path is empty or invalid: "' .
    // $this->sendmailPath . '"'). This branch is only reachable when the
    // *raw* (untrimmed) $this->sendmailPath is entirely whitespace (any
    // non-whitespace content survives trim() and preg_split() without
    // producing an empty first element -- see the trim() test above), so
    // the embedded "path" is necessarily just whitespace here; a single
    // space is enough to distinguish all 4 mutants via exact string
    // comparison (verified by hand for each below).
    $transport = new BoundedSendmailTransport(' ', 5.0);

    expect(static fn () => $transport->send(testEmail()))
        ->toThrow(TransportException::class, 'BoundedSendmailTransport: sendmail_path is empty or invalid: " "');
});

/**
 * Confirmed-equivalent: line 62's FalseToTrue (`$binaryAndFlags === true`
 * instead of `=== false`) and BooleanOrToBooleanAnd on the first `||`
 * (`$binaryAndFlags === false && $binaryAndFlags === []` instead of `||`,
 * still `|| $binaryAndFlags[0] === ''` after it). preg_split('/\s+/', ...)
 * is called here with a hardcoded, always-valid pattern and no
 * PREG_SPLIT_NO_EMPTY flag: it can only return `false` for a malformed
 * pattern (impossible -- the pattern is a fixed literal) or backtrack/
 * recursion-limit exhaustion (not achievable by any \s+-only pattern,
 * which has no backtracking), and it can only return `[]` when
 * PREG_SPLIT_NO_EMPTY is set (not set here) -- with that flag absent,
 * splitting even an empty string still yields a 1-element array (['']).
 * So for every possible string $this->sendmailPath (its declared,
 * enforced type), `$binaryAndFlags === false` and `$binaryAndFlags ===
 * []` are both provably always false, making `(===false) === true` (the
 * FalseToTrue mutant) and `(===false) && (===[])` (the
 * BooleanOrToBooleanAnd mutant on the first `||`) collapse to the same
 * "always false" value as the real `=== false` sub-expression already is
 * -- neither mutation can change whether the overall `if` condition
 * evaluates true, which is driven entirely by the third clause,
 * `$binaryAndFlags[0] === ''`, in every reachable case. Confirmed live:
 * reapplying each mutation individually to line 62 and rerunning this
 * whole file (including the new trim()-whitespace and exact-message tests
 * above) leaves every test passing unchanged.
 */

test('__toString identifies this as the bounded native transport', function (): void {
    $transport = new BoundedSendmailTransport('/bin/true', 5.0);

    expect((string) $transport)->toBe('native://default (bounded)');
});
