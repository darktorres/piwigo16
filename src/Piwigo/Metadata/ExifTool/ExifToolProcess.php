<?php

declare(strict_types=1);

namespace Piwigo\Metadata\ExifTool;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Drives a single, persistent `exiftool -stay_open` process across many
 * `read()` calls -- one instance is one real batch session (a full sync
 * pass, or a single web request's own one-image lookup), not one process
 * per file. Profiling proved why this matters: 200 files took 37.5s as
 * naive per-file spawns (Perl's own interpreter boot + module load cost
 * paid every time) versus 1.4s reused across one process.
 *
 * Built on `Symfony\Component\Process\Process` (already a project
 * dependency), not raw `proc_open()` -- {@see \Piwigo\Mail\
 * BoundedSendmailTransport}'s own docblock documents a real prior bug in
 * this exact codebase where a raw `proc_open()`-based transport had no
 * timeout mechanism at all and hung a request for 2+ minutes on an
 * unresponsive process. `Process::setIdleTimeout()` bounds how long any
 * single `read()` may wait for its own response without imposing a fixed
 * ceiling on the whole batch's duration (`setTimeout()` alone would either
 * kill a large sync partway through or, set generously, give no real hang
 * protection) -- `InputStream` lets the same running process's stdin be
 * fed one command at a time, matching `-stay_open`'s own protocol.
 *
 * [SEC-66] File paths are sent to ExifTool one per line over its `-@ -`
 * stdin argfile protocol via `InputStream`, never interpolated into a
 * shell command string -- there is no shell involved at all for per-file
 * reads (`Process`'s own array-form constructor command is fixed and
 * code-controlled: `[$binary, '-stay_open', 'True', '-@', '-']`). The one
 * real requirement this stdin protocol imposes is that a path cannot
 * itself contain a literal newline byte, which would be misread as a
 * command boundary. Every real caller supplies a path built by Piwigo's
 * own storage/site-tree logic (a filesystem scan, or a generated
 * upload-storage path) -- never raw, unsanitized end-user text -- so this
 * is a documented, accepted design constraint, not a live injection
 * vector.
 */
final class ExifToolProcess
{
    /**
     * Default for $idleTimeoutSeconds -- how long a single read() may wait
     * for its own {readyN} response before the process is treated as hung.
     * Resets on every chunk of output received (Process::$lastOutputTime),
     * not a ceiling on the whole batch's total duration. Constructor-
     * injectable (not a bare class constant) so tests can exercise the
     * hang-detection/restart path in well under a second instead of
     * genuinely waiting 30s.
     */
    private const float DEFAULT_IDLE_TIMEOUT_SECONDS = 30.0;

    private Process $process;

    private InputStream $inputStream;

    private string $buffer = '';

    private int $executeCounter = 0;

    public function __construct(
        private readonly string $binary = 'exiftool',
        private readonly float $idleTimeoutSeconds = self::DEFAULT_IDLE_TIMEOUT_SECONDS,
    ) {
        $this->start();
    }

    private function start(): void
    {
        // Checked explicitly, not left to Process::start()'s own
        // ProcessStartFailedException -- confirmed live that a missing
        // executable does NOT reliably throw synchronously (proc_open()'s
        // own fork() can succeed even though the exec() inside the child
        // then fails, a well-known limitation), so without this check a
        // missing binary would silently surface later as a confusing
        // ProcessTimedOutException from the dead-process detection in
        // doRead() instead of a clear, actionable message here.
        if (! self::commandExists($this->binary)) {
            throw new RuntimeException(
                'Could not find the "' . $this->binary . '" executable. Piwigo requires ExifTool to '
                . 'read photo metadata -- install it (e.g. `apt-get install libimage-exiftool-perl` on '
                . 'Debian/Ubuntu, or the equivalent package for your OS) and ensure it is on PATH.',
            );
        }

        $inputStream = new InputStream();
        $process = new Process([$this->binary, '-stay_open', 'True', '-@', '-']);
        $process->setInput($inputStream);
        $process->setTimeout(null);
        $process->setIdleTimeout($this->idleTimeoutSeconds);

        try {
            $process->start();
        } catch (ProcessStartFailedException $e) {
            // Secondary safety net for a start failure commandExists()
            // above didn't catch (e.g. a permissions problem on the exact
            // resolved path) -- the common "not installed at all" case is
            // already handled synchronously above.
            throw new RuntimeException(
                'Could not start the exiftool process. Piwigo requires ExifTool to read photo '
                . 'metadata -- install it (e.g. `apt-get install libimage-exiftool-perl` on '
                . 'Debian/Ubuntu, or the equivalent package for your OS) and ensure it is on PATH.',
                previous: $e,
            );
        }

        $this->process = $process;
        $this->inputStream = $inputStream;
        $this->buffer = '';
        $this->executeCounter = 0;
    }

    /**
     * Memoized `command -v exiftool` probe, matching {@see
     * \Piwigo\Admin\Image\ImageBackend::getExtImagickCommand()}'s own
     * already-established convention for detecting an external tool
     * (`static $available = null` local, since a static method has no
     * instance to hold state on).
     */
    public static function isAvailable(): bool
    {
        static $available = null;

        if (! is_bool($available)) {
            $available = self::commandExists('exiftool');
        }

        return $available;
    }

    private static function commandExists(string $binary): bool
    {
        $cmdOut = [];
        $retval = null;
        // [SEC-16] escapeshellarg() even though $binary is either the
        // fixed, code-controlled default ('exiftool') or a test's own
        // fixed fake-script path -- never end-user input -- matching
        // ImageBackend's own belt-and-suspenders precedent for the same
        // probe shape.
        exec('command -v ' . escapeshellarg($binary), $cmdOut, $retval);

        return $retval === 0;
    }

    /**
     * One file's metadata, keyed by tag name exactly as ExifTool's own
     * `-json` output names it (e.g. `Make`, `Keywords`) -- callers request
     * only the tags they need rather than a full dump, keeping the JSON
     * payload and parsing cost down at genuine sync scale.
     *
     * @param  list<string>  $tagNames
     * @return array<string, mixed>|null null if the file couldn't be read
     */
    public function read(string $path, array $tagNames): ?array
    {
        if (! $this->process->isRunning()) {
            $this->start();
        }

        try {
            return $this->doRead($path, $tagNames);
        } catch (ProcessTimedOutException) {
            // Hung or crashed mid-batch (a real, not hypothetical, risk
            // across a 10,000+ image sync sharing one process) -- restart
            // and retry this one file exactly once rather than losing
            // every remaining file in the batch.
            $this->start();

            return $this->doRead($path, $tagNames);
        }
    }

    /**
     * @param  list<string>  $tagNames
     * @return array<string, mixed>|null
     */
    private function doRead(string $path, array $tagNames): ?array
    {
        $marker = ++$this->executeCounter;

        $lines = array_map(static fn (string $tag): string => '-' . $tag, $tagNames);
        $lines[] = '-json';
        $lines[] = '-a'; // allow duplicate tags -> array, needed for multi-value IPTC fields (Keywords)
        $lines[] = $path;
        $lines[] = '-execute' . $marker;

        $this->inputStream->write(implode("\n", $lines) . "\n");

        $needle = '{ready' . $marker . '}';
        while (! str_contains($this->buffer, $needle)) {
            // checkTimeout() alone only covers a process that's still
            // running but exceeded its idle/overall budget -- it's
            // documented to no-op once the process has actually exited
            // (Process::$status leaves STATUS_STARTED), so a process that
            // dies outright mid-read (killed, segfaulted) needs its own
            // explicit liveness check here, or this loop would busy-spin
            // on a dead process forever instead of ever detecting it.
            $this->process->checkTimeout();
            if (! $this->process->isRunning()) {
                throw new ProcessTimedOutException($this->process, ProcessTimedOutException::TYPE_GENERAL);
            }
            $this->buffer .= $this->process->getIncrementalOutput();
            if (! str_contains($this->buffer, $needle)) {
                usleep(1_000);
            }
        }

        $endPos = strpos($this->buffer, $needle);
        // strpos() above already proved $needle exists in $this->buffer,
        // so this is never false -- only typed narrower for PHPStan.
        $endPos = $endPos === false ? strlen($this->buffer) : $endPos;
        $jsonPart = substr($this->buffer, 0, $endPos);
        $this->buffer = substr($this->buffer, $endPos + strlen($needle));

        $decoded = json_decode($jsonPart, true);
        if (! is_array($decoded) || ! isset($decoded[0]) || ! is_array($decoded[0])) {
            return null;
        }

        /** @var array<string, mixed> */
        return $decoded[0];
    }

    /**
     * Clean `-stay_open False` shutdown -- best-effort: a timeout or any
     * other failure while shutting down is swallowed here rather than
     * thrown, since this runs from a `finally` block whose whole purpose
     * is cleanup, not surfacing a new error over whatever's already being
     * handled.
     */
    public function close(): void
    {
        if (! $this->process->isRunning()) {
            return;
        }

        try {
            $this->inputStream->write("-stay_open\nFalse\n");
            $this->inputStream->close();
            $this->process->wait();
        } catch (Throwable) {
            $this->process->stop();
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
