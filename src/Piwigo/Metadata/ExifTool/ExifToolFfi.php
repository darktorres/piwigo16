<?php

declare(strict_types=1);

namespace Piwigo\Metadata\ExifTool;

use FFI;
use RuntimeException;

/**
 * Extracts photo metadata via the vendored exiftool-rs fork's shared
 * library (`tools/exiftool-rs-fork/`, its own `src/ffi.rs`), loaded
 * in-process through PHP's FFI extension -- no subprocess, no
 * `-stay_open` stdio protocol, no polling loop. One instance loads the
 * library binding once (`FFI::cdef()`'s own header-parsing cost) and is
 * reused across many `read()` calls within one batch session (a full sync
 * pass, or a single web request's own one-image lookup), same lifecycle
 * shape as the subprocess design this replaced, now for a different
 * reason: avoiding repeated `FFI::cdef()` parsing rather than repeated
 * process-spawn cost.
 *
 * Replaces an earlier subprocess-based design (`Process` +
 * `exiftool-rs -stay_open`) after benchmarking proved FFI's per-file cost
 * is 5-6x lower (~2ms -> ~0.35ms/file at 500-50,000 files) with no
 * per-call round-trip or poll loop at all. The tradeoff, accepted
 * deliberately: this now runs the extraction engine's own Rust code
 * in-process, so a crash there takes the calling PHP worker down with it,
 * unlike the old subprocess design's transparent crash-detect-and-restart
 * (`Process::isRunning()` + one retry). exiftool-rs's own upstream had six
 * real, now-patched bugs (see the fork's README.md); none of them were
 * memory-safety issues, and Rust's own guarantees rule out most of the
 * failure class a crash-isolation boundary exists to contain in the first
 * place -- but it is a real, not hypothetical, difference in blast radius.
 *
 * `ExifToolFfi` still reuses the fork's `-stay_open`/CLI-specific bug
 * fixes indirectly: `src/ffi.rs` and the CLI's own `-j` output both call
 * the same shared `write_json_tags()` (`src/json_output.rs`), so the
 * priority-based duplicate resolution and per-tag numeric (`#`) selection
 * fixes apply identically to both.
 */
final class ExifToolFfi
{
    private const string DEFAULT_LIBRARY_PATH = '/usr/local/lib/piwigo/libexiftool_rs.so';

    private FFI $ffi;

    public function __construct(
        private readonly string $libraryPath = self::DEFAULT_LIBRARY_PATH,
    ) {
        if (! self::libraryExists($this->libraryPath)) {
            throw new RuntimeException(
                'Could not find the exiftool-rs shared library at "' . $this->libraryPath . '". Piwigo requires '
                . 'it to read photo metadata -- the Docker image builds it automatically from '
                . 'tools/exiftool-rs-fork/; for local development without Docker, build it yourself '
                . '(cd tools/exiftool-rs-fork && cargo build --release --locked --lib) and point '
                . 'ExifToolFfi at the resulting target/release/libexiftool_rs.so.',
            );
        }

        $this->ffi = FFI::cdef(
            'char* exiftool_rs_extract(const char* path, const char* tags_csv);
             void exiftool_rs_free(char* ptr);',
            $this->libraryPath,
        );
    }

    /**
     * Memoized library-file probe, matching {@see
     * \Piwigo\Admin\Image\ImageBackend::getExtImagickCommand()}'s own
     * already-established convention for detecting an external
     * dependency (`static $available = null` local, since a static method
     * has no instance to hold state on) -- adapted from a `command -v`
     * process probe to a plain file-existence check, since there is no
     * executable to look up on PATH here.
     */
    public static function isAvailable(): bool
    {
        static $available = null;

        if (! is_bool($available)) {
            $available = self::libraryExists(self::DEFAULT_LIBRARY_PATH);
        }

        return $available;
    }

    private static function libraryExists(string $path): bool
    {
        return is_file($path);
    }

    /**
     * One file's metadata, keyed by tag name exactly as the shared library's
     * own JSON output names it (e.g. `Make`, `Keywords`) -- callers request
     * only the tags they need rather than a full dump, keeping the JSON
     * payload and parsing cost down at genuine sync scale. Tag names follow
     * the same `-TAG`/`-GROUP:TAG`/`-TAG#` syntax the old subprocess
     * protocol used (minus the leading `-`); no tag name may contain a
     * literal comma, since that's this call's own field separator -- every
     * real caller builds tag names from fixed, code-controlled strings
     * (`MetadataService`'s own tag-translation tables), never end-user text.
     *
     * `$path` crosses the FFI boundary as a NUL-terminated C string, so a
     * path containing an embedded NUL byte would silently truncate there --
     * not a live concern, since no POSIX filesystem Piwigo runs on permits
     * a NUL byte in a filename at all.
     *
     * @param  list<string>  $tagNames
     * @return array<string, mixed>|null null if the file couldn't be read
     */
    public function read(string $path, array $tagNames): ?array
    {
        $resultPtr = $this->ffi->exiftool_rs_extract($path, implode(',', $tagNames));
        $json = FFI::string($resultPtr);
        $this->ffi->exiftool_rs_free($resultPtr);

        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> */
        return $decoded;
    }

    /**
     * No-op: there is no subprocess to shut down under FFI. Kept so
     * existing call sites' `finally { $exifTool->close(); }` cleanup
     * blocks (written for the old subprocess design) don't need touching.
     */
    public function close(): void {}
}
