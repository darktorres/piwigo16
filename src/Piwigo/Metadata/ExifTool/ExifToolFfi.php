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
    /**
     * Where the Docker image's `production`/`production-apache` stages
     * place the library after building it from `tools/exiftool-rs-fork/`
     * -- the real, deployed convention on Linux. Local Linux/macOS
     * development without Docker instead points {@see resolveLibraryPath()}
     * at the fork's own `cargo build` output; there is no equivalent
     * deployed convention for Windows at all (Piwigo never ships a Windows
     * production image), so Windows always resolves to that same
     * local-build path too.
     */
    private const string DEFAULT_LIBRARY_PATH = '/usr/local/lib/piwigo/libexiftool_rs.so';

    /**
     * `cargo build`'s own per-platform output name/extension for the
     * fork's `crate-type = ["cdylib"]` lib target -- not a Piwigo
     * convention, `rustc`'s.
     */
    private const string LOCAL_BUILD_LIBRARY_BASENAME_UNIX = 'libexiftool_rs.so';

    private const string LOCAL_BUILD_LIBRARY_BASENAME_WINDOWS = 'exiftool_rs.dll';

    private const string LOCAL_BUILD_RELATIVE_DIR = '/tools/exiftool-rs-fork/target/release/';

    private const string LIBRARY_PATH_ENV_VAR = 'PIWIGO_EXIFTOOL_RS_LIBRARY_PATH';

    private readonly string $libraryPath;

    private FFI $ffi;

    /**
     * `$libraryPath` stays a plain nullable param (not a promoted
     * property defaulting to a constant) because its real default --
     * {@see resolveLibraryPath()} -- isn't a compile-time constant
     * expression PHP allows in a parameter default position; `null` means
     * "resolve it", an explicit string still overrides everything (tests
     * use this to point at a disposable fixture library).
     */
    public function __construct(?string $libraryPath = null)
    {
        $this->libraryPath = $libraryPath ?? self::resolveLibraryPath();

        if (! self::libraryExists($this->libraryPath)) {
            throw new RuntimeException(
                'Could not find the exiftool-rs shared library at "' . $this->libraryPath . '". Piwigo requires '
                . 'it to read photo metadata -- the Docker image builds it automatically from '
                . 'tools/exiftool-rs-fork/; for local development without Docker (on any OS, Windows included), '
                . 'build it yourself (cd tools/exiftool-rs-fork && cargo build --release --locked --lib) and '
                . 'either let ' . self::LIBRARY_PATH_ENV_VAR . ' resolve automatically -- see resolveLibraryPath() '
                . '-- or set that environment variable to the exact built library path.',
            );
        }

        $this->ffi = FFI::cdef(
            'char* exiftool_rs_extract(const char* path, const char* tags_csv);
             void exiftool_rs_free(char* ptr);',
            $this->libraryPath,
        );
    }

    /**
     * Resolves where to load the shared library from, in priority order:
     * 1. `PIWIGO_EXIFTOOL_RS_LIBRARY_PATH` env var, verbatim, when set --
     *    the escape hatch for any layout this method doesn't already
     *    know about (a non-default local build output dir, a packaging
     *    format that places it somewhere else, ...).
     * 2. The Docker-deployed `DEFAULT_LIBRARY_PATH` on non-Windows, since
     *    that's real production's own convention and shouldn't need an
     *    env var just to work there.
     * 3. Otherwise (Windows always lands here, since Piwigo has no
     *    Windows production image; non-Windows lands here only when
     *    `DEFAULT_LIBRARY_PATH` doesn't exist, i.e. local dev without
     *    Docker) the fork's own local `cargo build` output, using
     *    `cargo`'s per-platform basename -- resolved relative to this
     *    file's own location (`dirname(__DIR__, 4)` == the repo root,
     *    matching this codebase's established `dirname(__DIR__, N)`
     *    convention, e.g. `Cache\CacheFactory`/`Core\Container`) rather
     *    than requiring a `Paths` dependency this otherwise-DI-free,
     *    directly-`new`'d class doesn't have.
     *
     * The env var means a Windows dev never has to fake a `.so`-suffixed
     * path for the loader again: PHP's FFI resolves the *actual* built
     * `.dll` file exactly where `cargo build` put it, extension and all.
     *
     * `$osFamily` defaults to the real `PHP_OS_FAMILY` constant (itself a
     * valid compile-time default expression, unlike a method call) --
     * overridable only so `ExifToolFfiTest` can exercise the Windows
     * branch's own path-construction logic on whatever OS the test suite
     * actually runs on, without needing a real Windows box or an actual
     * `.dll` on disk to do it.
     */
    public static function resolveLibraryPath(string $osFamily = PHP_OS_FAMILY): string
    {
        $override = getenv(self::LIBRARY_PATH_ENV_VAR);
        if (is_string($override) && $override !== '') {
            return $override;
        }

        if ($osFamily !== 'Windows' && self::libraryExists(self::DEFAULT_LIBRARY_PATH)) {
            return self::DEFAULT_LIBRARY_PATH;
        }

        $basename = $osFamily === 'Windows'
            ? self::LOCAL_BUILD_LIBRARY_BASENAME_WINDOWS
            : self::LOCAL_BUILD_LIBRARY_BASENAME_UNIX;

        return dirname(__DIR__, 4) . self::LOCAL_BUILD_RELATIVE_DIR . $basename;
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
            $available = self::libraryExists(self::resolveLibraryPath());
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
