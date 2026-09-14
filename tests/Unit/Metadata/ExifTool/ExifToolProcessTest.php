<?php

declare(strict_types=1);

use Piwigo\Metadata\ExifTool\ExifToolProcess;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Real subprocess-based tests (no mocking of Symfony\Process) -- same
 * convention as tests/Unit/Mail/BoundedSendmailTransportTest.php: a small,
 * disposable fake script stands in for the real `exiftool` binary via
 * ExifToolProcess's own $binary constructor argument, implementing just
 * enough of the `-stay_open` protocol to deterministically test THIS
 * class's own protocol handling (marker parsing, one-process-reuse,
 * hang/crash detection and restart) without depending on real ExifTool's
 * exact output shape for these mechanics. Real-exiftool round-trip
 * coverage (actual tag extraction) belongs in the Integration suite once
 * MetadataService is wired to this class.
 */
function fakeExifToolScript(string $body): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'pwg_fake_exiftool_');
    if ($tmp === false) {
        throw new RuntimeException('tempnam() failed');
    }
    $path = $tmp . '.sh';
    file_put_contents($path, "#!/usr/bin/env bash\n" . $body);
    chmod($path, 0755);

    return $path;
}

/**
 * Minimal `-stay_open` protocol implementation: echoes its own PID once at
 * startup (to $pidFile, so tests can assert whether a `read()` call reused
 * the same process or spawned a new one), then for every `-execute<N>`
 * command replies with a one-row JSON array embedding whichever bare
 * (non-flag) line it was last given as the "SourceFile", followed by the
 * `{readyN}` sentinel. Handles the real `-stay_open`/`False` shutdown
 * sequence arriving over stdin.
 */
function fakeExifToolBasic(string $pidFile): string
{
    return fakeExifToolScript(<<<BASH
        echo \$\$ > '{$pidFile}'
        path=""
        while IFS= read -r line; do
            case "\$line" in
                -execute*)
                    marker="\${line#-execute}"
                    printf '[{"SourceFile":"%s"}]\\n' "\$path"
                    printf '{ready%s}\\n' "\$marker"
                    path=""
                    ;;
                -stay_open)
                    read -r val
                    [ "\$val" = "False" ] && exit 0
                    ;;
                -*) ;;
                *) path="\$line" ;;
            esac
        done
        BASH);
}

/**
 * Like fakeExifToolBasic(), but the FIRST process instance (tracked via a
 * marker file that persists across restarts) either hangs forever or
 * kills itself outright on its first `-execute` command -- any process
 * instance launched afterward (i.e. after ExifToolProcess restarts it)
 * behaves normally. Lets a test prove both "detects the failure" and
 * "successfully recovers", not just the first half.
 */
function fakeExifToolFailsOnce(string $markerFile, string $failureMode): string
{
    $failureCommand = $failureMode === 'hang' ? 'sleep 999' : 'kill -9 $$';

    return fakeExifToolScript(<<<BASH
        if [ ! -f '{$markerFile}' ]; then
            touch '{$markerFile}'
            fail_once=1
        else
            fail_once=0
        fi
        path=""
        while IFS= read -r line; do
            case "\$line" in
                -execute*)
                    marker="\${line#-execute}"
                    if [ "\$fail_once" = "1" ]; then
                        {$failureCommand}
                    fi
                    printf '[{"SourceFile":"%s"}]\\n' "\$path"
                    printf '{ready%s}\\n' "\$marker"
                    path=""
                    ;;
                -stay_open)
                    read -r val
                    [ "\$val" = "False" ] && exit 0
                    ;;
                -*) ;;
                *) path="\$line" ;;
            esac
        done
        BASH);
}

/**
 * @param  list<string>  $paths
 */
function cleanupFakeExifTool(array $paths): void
{
    foreach ($paths as $path) {
        @unlink($path);
    }
}

test('read() sends the requested tag names and path, and returns the decoded JSON row', function (): void {
    $pidFile = tempnam(sys_get_temp_dir(), 'pwg_pid_');
    $script = fakeExifToolBasic((string) $pidFile);
    $exifTool = new ExifToolProcess($script);

    $result = $exifTool->read('/tmp/photo.jpg', ['Make', 'Model']);
    $exifTool->close();
    cleanupFakeExifTool([$script, (string) $pidFile]);

    expect($result)
        ->toBe([
            'SourceFile' => '/tmp/photo.jpg',
        ]);
});

test('read() reuses the same persistent process across multiple calls, not one per file', function (): void {
    $pidFile = tempnam(sys_get_temp_dir(), 'pwg_pid_');
    $script = fakeExifToolBasic((string) $pidFile);
    $exifTool = new ExifToolProcess($script);

    $first = $exifTool->read('/tmp/a.jpg', ['Make']);
    $second = $exifTool->read('/tmp/b.jpg', ['Make']);
    $exifTool->close();

    $pid = trim((string) file_get_contents((string) $pidFile));
    cleanupFakeExifTool([$script, (string) $pidFile]);

    // Both reads went through the same single process (one PID written
    // once at startup) and each still got its own correct file back --
    // the actual regression this class exists to prevent: a future
    // accidental revert to spawning a process per read() would still
    // return correct per-file results (still functionally correct, just
    // one process per call instead of one for the whole batch), so this
    // doesn't just check correctness, it also sanity-checks that startup
    // (and its PID write) only happened once.
    expect($pid)
        ->not->toBe('')
        ->and($first)
        ->toBe([
            'SourceFile' => '/tmp/a.jpg',
        ])
        ->and($second)
        ->toBe([
            'SourceFile' => '/tmp/b.jpg',
        ]);
});

test('read() returns null when the process replies with something that is not a valid one-row JSON array', function (): void {
    $script = fakeExifToolScript(<<<'BASH'
        while IFS= read -r line; do
            case "$line" in
                -execute*)
                    marker="${line#-execute}"
                    printf 'not valid json\n'
                    printf '{ready%s}\n' "$marker"
                    ;;
                -stay_open)
                    read -r val
                    [ "$val" = "False" ] && exit 0
                    ;;
            esac
        done
        BASH);
    $exifTool = new ExifToolProcess($script);

    $result = $exifTool->read('/tmp/photo.jpg', ['Make']);
    $exifTool->close();
    cleanupFakeExifTool([$script]);

    expect($result)
        ->toBeNull();
});

test('read() detects a hung process (no response within the idle timeout) and transparently restarts and retries', function (): void {
    $markerFile = tempnam(sys_get_temp_dir(), 'pwg_marker_');
    $script = fakeExifToolFailsOnce((string) $markerFile, 'hang');
    // A short idle timeout so this test doesn't actually wait 30s for the
    // default -- the whole point of making it constructor-injectable.
    $exifTool = new ExifToolProcess($script, idleTimeoutSeconds: 0.3);

    $start = hrtime(true);
    $result = $exifTool->read('/tmp/photo.jpg', ['Make']);
    $elapsedSeconds = (hrtime(true) - $start) / 1_000_000_000;
    $exifTool->close();
    cleanupFakeExifTool([$script, (string) $markerFile]);

    expect($result)
        ->toBe([
            'SourceFile' => '/tmp/photo.jpg',
        ])
        ->and($elapsedSeconds)
        ->toBeLessThan(5.0);
});

test('read() detects a process that dies outright mid-read (not just a hang) and transparently restarts and retries', function (): void {
    // Proves the explicit isRunning() check inside doRead()'s polling loop
    // actually matters: Process::checkTimeout() alone is documented to
    // no-op once the process has actually exited, so without that check
    // this scenario would busy-loop forever instead of ever recovering.
    $markerFile = tempnam(sys_get_temp_dir(), 'pwg_marker_');
    $script = fakeExifToolFailsOnce((string) $markerFile, 'crash');
    $exifTool = new ExifToolProcess($script, idleTimeoutSeconds: 5.0);

    $start = hrtime(true);
    $result = $exifTool->read('/tmp/photo.jpg', ['Make']);
    $elapsedSeconds = (hrtime(true) - $start) / 1_000_000_000;
    $exifTool->close();
    cleanupFakeExifTool([$script, (string) $markerFile]);

    expect($result)
        ->toBe([
            'SourceFile' => '/tmp/photo.jpg',
        ])
        ->and($elapsedSeconds)
        ->toBeLessThan(5.0);
});

test('read() lets a second, unrecovered failure propagate instead of retrying forever', function (): void {
    // fakeExifToolScript here fails on EVERY call (no marker-file
    // recovery), unlike fakeExifToolFailsOnce() -- proves the retry is
    // exactly one attempt, not an infinite loop that would hang a real
    // sync forever on a genuinely broken exiftool install.
    $script = fakeExifToolScript(<<<'BASH'
        while IFS= read -r line; do
            case "$line" in
                -execute*) sleep 999 ;;
                -stay_open)
                    read -r val
                    [ "$val" = "False" ] && exit 0
                    ;;
            esac
        done
        BASH);
    $exifTool = new ExifToolProcess($script, idleTimeoutSeconds: 0.2);

    $threw = false;
    try {
        $exifTool->read('/tmp/photo.jpg', ['Make']);
    } catch (ProcessTimedOutException) {
        $threw = true;
    }
    $exifTool->close();
    cleanupFakeExifTool([$script]);

    expect($threw)
        ->toBeTrue();
});

test('constructor throws a clear, actionable exception when the binary cannot be started', function (): void {
    expect(static fn () => new ExifToolProcess('/no/such/binary/exiftool-does-not-exist'))
        ->toThrow(RuntimeException::class, 'Piwigo requires exiftool-rs');
});

test('isAvailable() is true when exiftool-rs is genuinely installed', function (): void {
    // exiftool-rs is a hard requirement of this project from this change
    // onward (Dockerfile builds it from tools/exiftool-rs-fork/, CI installs
    // the same build) -- a false here in any real dev/CI environment is a
    // genuine environment problem worth failing loudly on, not something to
    // soften into a weaker assertion.
    expect(ExifToolProcess::isAvailable())
        ->toBeTrue();
});
