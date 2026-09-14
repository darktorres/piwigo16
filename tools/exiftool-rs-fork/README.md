# exiftool-rs (patched fork, vendored)

A trimmed, locally-patched copy of [exiftool-rs](https://github.com/Le-Syl21/exiftool-rs)
0.8.0 (forked from upstream commit `51d38a30dc92e5d71b0d41a211ca140adb0851b9`), built by
Piwigo's Docker image as the `Piwigo\Metadata\ExifTool\ExifToolProcess` backend instead of
real (Perl) ExifTool.

Only what's needed to build the plain `exiftool-rs` CLI binary is vendored here --
`src/`, `Cargo.toml`, `Cargo.lock`, `build.rs`, `LICENSE`, `locales/` (the GUI binary,
its icon assets, the crate's own test suite, and its dev scripts are all dropped).
`locales/` is required at compile time (`i18n.rs` embeds every file via `include_str!`),
even though this integration never passes `-lang`.

## License

**GPLv3+** (see `LICENSE`) -- more restrictive than real ExifTool's own Artistic/GPL-1+
dual license. Piwigo only ever invokes the compiled binary as a separate subprocess
(the same `-stay_open` stdio protocol used for real ExifTool), never links against it,
so this doesn't bring Piwigo itself under the GPL -- but shipping the compiled binary
inside Piwigo's Docker image does carry GPLv3's own distribution obligation to offer
corresponding source for *that* binary, which this vendored, patched copy satisfies.

## Why a patched fork, not upstream as-is

Investigated as a faster alternative to real ExifTool (no per-file process-spawn cost
was already solved by real ExifTool's own `-stay_open` batching, so the appeal here is
its Rust engine's per-request parsing speed within the same batching model). Six real
bugs were found -- several of them only by wiring this up against Piwigo's own
`ExifToolProcess` end-to-end, not by testing the CLI in isolation -- each patched here
rather than worked around:

1. **`#` numeric-suffix requests silently matched nothing.** `-TAG#`/`-GROUP:TAG#`
   (ExifTool's per-tag "give me the raw, non-print-converted value" syntax --
   `MetadataService::getExifData()`'s own GPS lookup depends on this exact form) left
   the `#` attached to the string compared against real tag names in
   `tag_matches_request()`, so it never matched anything. Fixed in `apply_tag_request()`
   (`src/main.rs`): strip the suffix, track the bare tag name in a new
   `Options::numeric_tags` set, and select the raw value over the print-converted one
   per-tag in `get_info()` (`src/exiftool.rs`) when that tag was requested this way.

2. **Composite/GPS tags weren't reachable via `-GROUP:TAG` at all.** Real ExifTool's
   `Composite::GPSLatitude`/`GPSLongitude`/etc. are also addressable via the `GPS:`
   group even though they're derived tags (`exiftool -GPS:GPSLatitude# file.jpg` works
   against real ExifTool). This crate files them only under the `Composite` group
   family (confirmed via `-G1`), so `-GPS:GPSLatitude` silently matched nothing. Fixed
   in `tag_matches_request()` (`src/exiftool.rs`) with an explicit alias: a `Composite`-
   family tag whose name starts with `gps` also matches a requested `gps` group.

3. **`-stay_open` mode didn't apply per-request options at all**, and didn't implement
   the numbered `-executeN`/`{readyN}` marker protocol real ExifTool (and this
   integration's `ExifToolProcess`) actually uses. The original `run_stay_open()`
   recognized only a bare `-execute` line (any real `-executeN` fell through as an
   unrecognized, silently-ignored line) and always answered with whatever `Options`
   the process started with -- every per-request `-TAG`/`-json`/`-a` line sent over
   stdin was dropped on the floor, so every request returned *all* tags, every time,
   regardless of what was actually asked for. Rewritten in `run_stay_open()`
   (`src/main.rs`) to parse each request's own accumulated lines into a fresh,
   request-scoped `Options` (reusing `apply_tag_request()`) before extracting, and to
   echo `{ready<marker>}` matching whatever suffix (numeric or empty) the client sent
   on `-execute<marker>`. Scoped to exactly what a real `-stay_open` client sends (a
   full tag list + `-json` + `-a` per request) -- this does not attempt to replicate
   real ExifTool's own sticky-until-changed option semantics across `-execute` calls.

4. **`-stay_open` JSON output wasn't array-wrapped.** Real ExifTool's `-j` output is
   always a JSON array, `[{...}]`, even for one file, even in `-stay_open` mode -- every
   documented client (`ExifToolProcess::doRead()` included) does `json_decode($resp)[0]`.
   The original `run_stay_open()` called `print_json_tags()` directly with no
   surrounding `[`/`]` (only its one-shot CLI sibling, `print_json_all()`, added them),
   so every single stay-open response was a bare object with no `[0]` to index --
   `ExifToolProcess::read()` silently returned `null` for every file, every time. This
   was the one bug that made every other fix above look like it hadn't worked: each of
   patches 1-3 was verified against the one-shot CLI (`exiftool-rs -j ...`, which was
   never broken this way) before this integration test caught that the actual
   `-stay_open` path Piwigo uses was still returning nothing. Fixed by wrapping the
   per-request `print_json_tags()` calls in `run_stay_open()` with `[`/`]`, matching
   `print_json_all()`'s own structure.

5. **The `#` numeric raw value for `GPSLatitude`/`GPSLongitude` had no sign or decimal
   conversion at all.** Even once patch 1 correctly routed a `#` request to
   `tag.raw_value`, that raw value was still whatever `composite::gps_coordinates()`
   had cloned it from -- the plain GPS-family tag's *own* raw value, a 3-element
   rational list (degrees, minutes, seconds), with no `ValueConv` applied and no
   hemisphere sign. Real ExifTool's `GPS.pm` applies `Image::ExifTool::GPS::ToDegrees`
   to the plain tag *before* this composite ever runs, so `$val[0]` in its own
   `ValueConv => '$val[1] =~ /^S/i ? -$val[0] : $val[0]'` is already a signed-magnitude
   decimal float, not a raw rational triple -- this crate never replicated that
   conversion at all. Fixed in `gps_coordinates()` (`src/composite.rs`): parse the
   3-rational raw value into decimal degrees (`deg + min/60 + sec/3600`) and negate on
   a South/West ref, storing the result as `Value::F64` for the composite's own
   `raw_value` (the DMS-formatted `print_value` is untouched).

6. **The GPS composite lost the "no-dup" tiebreak to its own plain tag.** Real
   ExifTool's `GPS.pm` gives `Composite::GPSLatitude`/`GPSLongitude` an explicit
   `Priority => 1` specifically so it outranks the plain `GPS:GPSLatitude` tag of the
   same name (already noted, but not implemented, in this function's own pre-existing
   docblock comment) -- without it, `-a`/`-duplicates` re-exposing the plain tag
   alongside the composite let *first-in-the-tags-vector* decide the JSON key instead of
   priority, and the plain tag (built before composites) always came first. Since
   `ExifToolProcess` always sends `-a` (for legitimate multi-value fields like IPTC
   Keywords), this silently discarded patch 5's correct signed decimal in favor of the
   plain tag's unsigned rational-list display every single time in practice. Two fixes,
   both needed: `gps_coordinates()` now sets `t.priority = 1` on the composite (matching
   real ExifTool's own documented value), and `print_json_tags()`'s (`src/main.rs`)
   duplicate-key resolution was rewritten from first-occurrence-wins to
   priority-rank-wins (mirroring `get_info()`'s own already-correct logic), so a later,
   higher-priority tag can actually win the JSON key regardless of vector order.

Patches 5 and 6 together are what make GPS extraction genuinely correct through this
fork -- both magnitude and hemisphere sign -- verified against real ExifTool's own
output for all four hemisphere combinations, through the exact stay-open protocol and
tag-request shape `ExifToolProcess` uses, not just the one-shot CLI.
