# exiftool-rs (patched fork, vendored)

A trimmed, locally-patched copy of
[exiftool-rs](https://github.com/Le-Syl21/exiftool-rs) 0.8.0 (forked from
upstream commit `51d38a30dc92e5d71b0d41a211ca140adb0851b9`), built as a
shared library and loaded in-process (via PHP's `ext-ffi`, `FFI::cdef()`)
by `Piwigo\Metadata\ExifTool\ExifToolFfi` instead of real (Perl) ExifTool.
An earlier revision of this integration (`ExifToolProcess`) ran the
crate's CLI binary as a subprocess over its `-stay_open` protocol; that
design is now retired in favor of loading the compiled `.so` directly,
after benchmarking proved FFI's per-file cost is 5-6x lower with no
subprocess round-trip or polling loop -- see git history for the
subprocess-era design and its own bug writeups (bugs 3-4 below) if
reviving it.

Only what's needed to build the crate's library target (`--lib`, the
cdylib `ExifToolFfi` loads) is vendored here -- `src/`, `Cargo.toml`,
`Cargo.lock`, `build.rs`, `LICENSE`, `locales/` (the GUI binary, its icon
assets, the crate's own test suite, and its dev scripts are all
dropped). The CLI binary (`--bin exiftool-rs`) still builds from this
same vendored source and is kept for manual debugging of the underlying
engine (see `src/main.rs`'s own `-stay_open` support, still correct and
tested, just no longer invoked by Piwigo's runtime) -- Piwigo's Docker
image and CI only build `--lib` now. `locales/` is required at compile
time (`i18n.rs` embeds every file via `include_str!`), even though this
integration never passes `-lang`.

## License

**GPLv3+** (see `LICENSE`) -- more restrictive than real ExifTool's own
Artistic/GPL-1+ dual license, and the licensing analysis here is now
stronger, not just a formality: this crate's compiled code is loaded
**in-process** via FFI (`dlopen()` under the hood) and called directly
from the same PHP process, not run as a separate subprocess talking over
stdio. GPL's own linking analysis draws a real distinction between the
two -- "mere aggregation" (separate processes, communicating at arm's
length) is the traditional argument for why invoking a GPL program as a
subprocess doesn't bring the caller under the GPL, and that argument is
materially weaker for a library loaded and called directly within the
same running process, regardless of the load mechanism being dynamic
(FFI/`dlopen`) rather than a compile-time link. This wasn't the case
when this integration ran the CLI as a subprocess (see the retired
`ExifToolProcess` design above); it is worth a real legal review before
treating this as settled, rather than assuming the previous
subprocess-based conclusion still holds under the new design.

## Why a patched fork, not upstream as-is

Investigated as a faster alternative to real ExifTool (no per-file
process-spawn cost was already solved by real ExifTool's own
`-stay_open` batching, so the appeal here is its Rust engine's
per-request parsing speed within the same batching model). Six real
bugs were found -- several of them only by wiring this up against
Piwigo's own `ExifToolProcess` end-to-end, not by testing the CLI in
isolation -- each patched here rather than worked around:

1. **`#` numeric-suffix requests silently matched nothing.**
   `-TAG#`/`-GROUP:TAG#` (ExifTool's per-tag "give me the raw,
   non-print-converted value" syntax -- `MetadataService::getExifData()`'s
   own GPS lookup depends on this exact form) left the `#` attached to
   the string compared against real tag names in `tag_matches_request()`,
   so it never matched anything. Fixed in `apply_tag_request()`
   (`src/main.rs`): strip the suffix, track the bare tag name in a new
   `Options::numeric_tags` set, and select the raw value over the
   print-converted one per-tag in `get_info()` (`src/exiftool.rs`) when
   that tag was requested this way.

2. **Composite/GPS tags weren't reachable via `-GROUP:TAG` at all.** Real
   ExifTool's `Composite::GPSLatitude`/`GPSLongitude`/etc. are also
   addressable via the `GPS:` group even though they're derived tags
   (`exiftool -GPS:GPSLatitude# file.jpg` works against real ExifTool).
   This crate files them only under the `Composite` group family
   (confirmed via `-G1`), so `-GPS:GPSLatitude` silently matched
   nothing. Fixed in `tag_matches_request()` (`src/exiftool.rs`) with an
   explicit alias: a `Composite`-family tag whose name starts with `gps`
   also matches a requested `gps` group.

3. **`-stay_open` mode didn't apply per-request options at all**, and
   didn't implement the numbered `-executeN`/`{readyN}` marker protocol
   real ExifTool (and this integration's `ExifToolProcess`) actually
   uses. The original `run_stay_open()` recognized only a bare
   `-execute` line (any real `-executeN` fell through as an
   unrecognized, silently-ignored line) and always answered with
   whatever `Options` the process started with -- every per-request
   `-TAG`/`-json`/`-a` line sent over stdin was dropped on the floor, so
   every request returned *all* tags, every time, regardless of what
   was actually asked for. Rewritten in `run_stay_open()`
   (`src/main.rs`) to parse each request's own accumulated lines into a
   fresh, request-scoped `Options` (reusing `apply_tag_request()`)
   before extracting, and to echo `{ready<marker>}` matching whatever
   suffix (numeric or empty) the client sent on `-execute<marker>`.
   Scoped to exactly what a real `-stay_open` client sends (a full tag
   list + `-json` + `-a` per request) -- this does not attempt to
   replicate real ExifTool's own sticky-until-changed option semantics
   across `-execute` calls.

4. **`-stay_open` JSON output wasn't array-wrapped.** Real ExifTool's
   `-j` output is always a JSON array, `[{...}]`, even for one file,
   even in `-stay_open` mode -- every documented client
   (`ExifToolProcess::doRead()` included) does `json_decode($resp)[0]`.
   The original `run_stay_open()` called `print_json_tags()` directly
   with no surrounding `[`/`]` (only its one-shot CLI sibling,
   `print_json_all()`, added them), so every single stay-open response
   was a bare object with no `[0]` to index -- `ExifToolProcess::read()`
   silently returned `null` for every file, every time. This was the
   one bug that made every other fix above look like it hadn't worked:
   each of patches 1-3 was verified against the one-shot CLI
   (`exiftool-rs -j ...`, which was never broken this way) before this
   integration test caught that the actual `-stay_open` path Piwigo
   uses was still returning nothing. Fixed by wrapping the per-request
   `print_json_tags()` calls in `run_stay_open()` with `[`/`]`, matching
   `print_json_all()`'s own structure.

5. **The `#` numeric raw value for `GPSLatitude`/`GPSLongitude` had no
   sign or decimal conversion at all.** Even once patch 1 correctly
   routed a `#` request to `tag.raw_value`, that raw value was still
   whatever `composite::gps_coordinates()` had cloned it from -- the
   plain GPS-family tag's *own* raw value, a 3-element rational list
   (degrees, minutes, seconds), with no `ValueConv` applied and no
   hemisphere sign. Real ExifTool's `GPS.pm` applies
   `Image::ExifTool::GPS::ToDegrees` to the plain tag *before* this
   composite ever runs, so `$val[0]` in its own
   `ValueConv => '$val[1] =~ /^S/i ? -$val[0] : $val[0]'` is already a
   signed-magnitude decimal float, not a raw rational triple -- this
   crate never replicated that conversion at all. Fixed in
   `gps_coordinates()` (`src/composite.rs`): parse the 3-rational raw
   value into decimal degrees (`deg + min/60 + sec/3600`) and negate on
   a South/West ref, storing the result as `Value::F64` for the
   composite's own `raw_value` (the DMS-formatted `print_value` is
   untouched).

6. **The GPS composite lost the "no-dup" tiebreak to its own plain
   tag.** Real ExifTool's `GPS.pm` gives
   `Composite::GPSLatitude`/`GPSLongitude` an explicit `Priority => 1`
   specifically so it outranks the plain `GPS:GPSLatitude` tag of the
   same name (already noted, but not implemented, in this function's
   own pre-existing docblock comment) -- without it, `-a`/`-duplicates`
   re-exposing the plain tag alongside the composite let
   *first-in-the-tags-vector* decide the JSON key instead of priority,
   and the plain tag (built before composites) always came first. Since
   `ExifToolProcess` always sends `-a` (for legitimate multi-value
   fields like IPTC Keywords), this silently discarded patch 5's
   correct signed decimal in favor of the plain tag's unsigned
   rational-list display every single time in practice. Two fixes, both
   needed: `gps_coordinates()` now sets `t.priority = 1` on the
   composite (matching real ExifTool's own documented value), and
   `print_json_tags()`'s (`src/main.rs`) duplicate-key resolution was
   rewritten from first-occurrence-wins to priority-rank-wins
   (mirroring `get_info()`'s own already-correct logic), so a later,
   higher-priority tag can actually win the JSON key regardless of
   vector order.

Patches 5 and 6 together are what make GPS extraction genuinely correct
through this fork -- both magnitude and hemisphere sign -- verified
against real ExifTool's own output for all four hemisphere
combinations, through the exact stay-open protocol and tag-request
shape the retired `ExifToolProcess` design used, not just the one-shot
CLI.

## FFI: the in-process module (`src/ffi.rs`)

`ExifToolFfi` calls two `#[no_mangle] extern "C"` functions,
`exiftool_rs_extract()` and `exiftool_rs_free()`, added in `src/ffi.rs`
and exported by adding `"cdylib"` to this crate's `[lib]` `crate-type`
in `Cargo.toml` (alongside the default `"rlib"`, which the CLI binary
still needs). `exiftool_rs_extract()` parses a comma-separated
`-TAG`/`-GROUP:TAG`/`-TAG#` request list (the same syntax
`apply_tag_request()` parses for the CLI, reimplemented rather than
shared since it's a few lines either way) and returns a JSON object
string.

That JSON serialization itself **is** shared, not reimplemented:
`write_json_tags()` (`src/json_output.rs`) is the exact same
priority-dedup + per-tag-numeric-selection + array/scalar-JSON logic
bugs 4-6 above fixed, extracted out of the CLI's own
`print_json_tags()` (now a thin wrapper calling it with stdout) so both
the CLI and `ffi.rs` call one tested implementation instead of drifting
into two. Given how many of this crate's own bugs were exactly this
kind of duplicated-and-diverged logic, adding a *second* caller of that
logic without sharing it would have been a bug waiting to happen, not a
reasonable shortcut.
