//! `-json` object serialization, shared between the CLI's own `-j` output
//! (`main.rs`) and the in-process FFI module (`ffi.rs`) so the two can't
//! drift into two subtly different implementations of the same
//! non-trivial logic -- exactly the kind of duplication that produced this
//! fork's own bugs 4-6 (see this crate's top-level README.md).

use crate::tag::Tag;
use crate::value::Value;
use std::collections::{HashMap, HashSet};
use std::io::{self, Write};

/// True when the source is a standalone XMP file (FileType "XMP").
pub fn file_is_xmp(tags: &[Tag]) -> bool {
    tags.iter()
        .find(|t| t.name == "FileType")
        .map(|t| t.print_value == "XMP")
        .unwrap_or(false)
}

/// Whether to skip `-lang` value translation for this tag. In a standalone XMP
/// file ExifTool re-reads binary metadata (exif:*, MakerNotes, ...) back from the
/// XMP as their original groups but without running the PrintConv, so `-lang`
/// leaves those values in English; only genuine XMP-native tags (and the Composite
/// tags built from them) are localized. Match that by suppressing every non-XMP,
/// non-Composite group in an XMP file (`xmp_file` is precomputed per file).
pub fn suppress_value_lang(xmp_file: bool, tag: &Tag) -> bool {
    xmp_file && tag.group.family0 != "XMP" && tag.group.family0 != "Composite"
}

/// Group name to print for ExifTool's `-G<n>`/`-g<n>` family selector.
///
/// Families 4 to 6 (instance, path, format) are not modelled by [`crate::tag::TagGroup`],
/// so they fall back to family 1 rather than printing nothing.
pub fn group_for_family(tag: &Tag, family: u8) -> &str {
    match family {
        0 => &tag.group.family0,
        2 => &tag.group.family2,
        3 => &tag.group.family3,
        _ => &tag.group.family1,
    }
}

pub fn json_is_number(s: &str) -> bool {
    let b = s.as_bytes();
    let mut i = 0;
    if i < b.len() && b[i] == b'-' {
        i += 1;
    }
    // integer part: a lone digit, or [1-9] followed by 1..=14 more digits
    let int_start = i;
    if i >= b.len() || !b[i].is_ascii_digit() {
        return false;
    }
    if b[i] == b'0' {
        i += 1; // a single "0" is allowed only if nothing more follows the int part
    } else {
        i += 1;
        while i < b.len() && b[i].is_ascii_digit() && i - int_start <= 14 {
            i += 1;
        }
    }
    let int_len = i - int_start;
    if int_len > 1 && b[int_start] == b'0' {
        return false; // leading zero on a multi-digit integer
    }
    if int_len > 15 {
        return false;
    }
    // optional fractional part: '.' then 1..=16 digits
    if i < b.len() && b[i] == b'.' {
        i += 1;
        let frac_start = i;
        while i < b.len() && b[i].is_ascii_digit() {
            i += 1;
        }
        let frac_len = i - frac_start;
        if !(1..=16).contains(&frac_len) {
            return false;
        }
    }
    // optional exponent: e/E, optional sign, 1..=3 digits
    if i < b.len() && (b[i] == b'e' || b[i] == b'E') {
        i += 1;
        if i < b.len() && (b[i] == b'+' || b[i] == b'-') {
            i += 1;
        }
        let exp_start = i;
        while i < b.len() && b[i].is_ascii_digit() {
            i += 1;
        }
        let exp_len = i - exp_start;
        if !(1..=3).contains(&exp_len) {
            return false;
        }
    }
    i == b.len()
}

/// Elements to emit when a tag prints as a JSON array, or `None` for a scalar.
///
/// Mirrors ExifTool: a value that is still an ARRAY ref at print time renders as
/// a JSON array (exiftool `FormatJSON`, default `$joinLists` off). Our faithful
/// test is that `raw_value` is a `Value::List` whose plain per-element print join
/// reproduces `print_value` exactly -- meaning no scalar-collapsing conversion ran.
/// Collapsed lists (GPSLatitude/GPSPosition, whose ValueConv rewrites the whole
/// rational list into one formatted string) fail this test and stay scalar, which
/// is what ExifTool prints for them. The per-element strings are `print_value`'s
/// own segments, so any per-element PrintConv (ComponentsConfiguration -> Y/Cb/Cr,
/// PLUS vocab URIs -> phrases) is already reflected in each array element.
pub fn json_list_elements(tag: &Tag) -> Option<Vec<String>> {
    if let Value::List(items) = &tag.raw_value {
        let elems: Vec<String> = items.iter().map(|v| v.to_display_string()).collect();
        if elems.join(", ") == tag.print_value {
            return Some(elems);
        }
    }
    None
}

pub fn escape_json(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for ch in s.chars() {
        match ch {
            '\0' => {} // ExifTool removes all nulls
            '\\' => out.push_str("\\\\"),
            '"' => out.push_str("\\\""),
            '\t' => out.push_str("\\t"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            c if (c as u32) < 0x20 || c as u32 == 0x7f => {
                out.push_str(&format!("\\u{:04X}", c as u32));
            }
            c => out.push(c),
        }
    }
    out
}

/// Priority-based "no dup" resolution matching ExifTool's own `$noDups`
/// (`FoundTag`): the first occurrence of a key establishes it, and only a
/// STRICTLY higher-priority later tag replaces it -- never just "whichever
/// came first in the tags vector". A naive first-occurrence-wins silently
/// breaks the moment a lower-priority duplicate legitimately re-enters the
/// tags list (e.g. `-a`/`-duplicates` re-exposing the plain `GPS:GPSLatitude`
/// tag alongside the higher-priority `Composite:GPSLatitude`, which is always
/// built later): the plain tag would win the key by vector order alone,
/// discarding the Composite's correct value.
pub fn dedup_by_priority<'a>(
    tags: &'a [Tag],
    show_groups: bool,
    group_family: u8,
) -> Vec<(String, &'a Tag)> {
    let mut order: Vec<String> = Vec::new();
    let mut winners: HashMap<String, (&Tag, i32)> = HashMap::new();
    for tag in tags {
        let key = if show_groups {
            format!("{}:{}", group_for_family(tag, group_family), tag.name)
        } else {
            tag.name.clone()
        };
        let rank = tag.priority_rank();
        match winners.get_mut(&key) {
            None => {
                order.push(key.clone());
                winners.insert(key, (tag, rank));
            }
            Some(entry) => {
                if rank > entry.1 {
                    *entry = (tag, rank);
                }
            }
        }
    }
    order
        .into_iter()
        .map(|k| {
            let (tag, _) = winners[&k];
            (k, tag)
        })
        .collect()
}

/// First-occurrence-wins resolution for the `!dedup` (`-ee`) case: every
/// tag belonging to the main document shows, plus each key's first
/// occurrence regardless of document -- see `write_json_tags()`'s own
/// caller-facing docs for why this differs from the `dedup` case.
fn keep_first_or_main(tags: &[Tag], show_groups: bool, group_family: u8) -> Vec<(String, &Tag)> {
    let mut seen = HashSet::new();
    tags.iter()
        .filter_map(|tag| {
            let key = if show_groups {
                format!("{}:{}", group_for_family(tag, group_family), tag.name)
            } else {
                tag.name.clone()
            };
            let first = seen.insert(key.clone());
            if first || tag.group.family3 == "Main" {
                Some((key, tag))
            } else {
                None
            }
        })
        .collect()
}

/// Writes one file's `{"SourceFile": ..., "Tag": value, ...}` JSON object to
/// `w` -- no enclosing `[`/`]`, no trailing newline; callers that need those
/// (the CLI's array-of-files output, `-stay_open` mode) add them.
///
/// `dedup`: true for normal extraction (ExifTool's own `$noDups`, applied by
/// priority -- see [`dedup_by_priority`]); false only under `-ee`
/// (`extract_embedded`), where the primary/copy ordering of same-named tags
/// from competing embedded documents doesn't yet match ExifTool's, so
/// deduping everything would keep the wrong instance -- every tag belonging
/// to the main document still shows regardless (`FoundTag` never lets an
/// embedded-document tag hold a key already claimed by the main document).
///
/// `numeric_tags`: bare (group-stripped, lowercased) tag names requested
/// with ExifTool's per-tag `#` numeric-format suffix -- selects the raw,
/// non-print-converted value for just that tag, independent of any global
/// `-n`.
#[allow(clippy::too_many_arguments)]
pub fn write_json_tags<W: Write>(
    w: &mut W,
    tags: &[Tag],
    filename: &str,
    prepend_comma: bool,
    show_groups: bool,
    group_family: u8,
    dedup: bool,
    lang: Option<&str>,
    numeric_tags: &HashSet<String>,
) -> io::Result<()> {
    if prepend_comma {
        write!(w, ",")?;
    }
    let xmp_file = file_is_xmp(tags);
    writeln!(w, "{{")?;
    writeln!(w, "  \"SourceFile\": \"{}\",", escape_json(filename))?;

    let keyed: Vec<(String, &Tag)> = if dedup {
        dedup_by_priority(tags, show_groups, group_family)
    } else {
        keep_first_or_main(tags, show_groups, group_family)
    };

    for (i, (key, tag)) in keyed.iter().enumerate() {
        let numeric = numeric_tags.contains(&tag.name.to_lowercase());
        let raw_display;
        let translated;
        let value_str: &str = if numeric {
            raw_display = tag.raw_value.to_display_string();
            raw_display.as_str()
        } else {
            // With -lang, localize the scalar PrintConv value (ExifTool keeps
            // the tag name as the JSON key and translates only the value).
            // List values are per-element and not PrintConv-keyed, so they
            // are left untranslated. Numeric (#) values have no PrintConv to
            // localize either.
            translated = lang
                .filter(|_| !suppress_value_lang(xmp_file, tag))
                .and_then(|l| {
                    crate::i18n::translate_value(l, &tag.group.family1, &tag.name, &tag.print_value)
                });
            translated.as_deref().unwrap_or(tag.print_value.as_str())
        };
        let comma = if i + 1 < keyed.len() { "," } else { "" };
        // ExifTool's FormatJSON prints an ARRAY-ref value as a JSON array `[...]`
        // (unless `$joinLists`, only set by -sep/-List -- never in default mode).
        if let Some(elems) = json_list_elements(tag) {
            write!(w, "  \"{}\": [", key)?;
            for (j, el) in elems.iter().enumerate() {
                let sep = if j + 1 < elems.len() { "," } else { "" };
                if json_is_number(el) {
                    write!(w, "{}{}", el, sep)?;
                } else if el.eq_ignore_ascii_case("true") || el.eq_ignore_ascii_case("false") {
                    write!(w, "{}{}", el.to_ascii_lowercase(), sep)?;
                } else {
                    write!(w, "\"{}\"{}", escape_json(el), sep)?;
                }
            }
            writeln!(w, "]{}", comma)?;
            continue;
        }
        // Scalar typing mirrors ExifTool's EscapeJSON (exiftool:3806-3810): a
        // value is emitted unquoted as a JSON number only if it matches its
        // number regex, and "true"/"false" (case-insensitive) become lowercase
        // JSON booleans. Everything else is a quoted, escaped string.
        if json_is_number(value_str) {
            writeln!(w, "  \"{}\": {}{}", key, value_str, comma)?;
        } else if value_str.eq_ignore_ascii_case("true") || value_str.eq_ignore_ascii_case("false") {
            writeln!(w, "  \"{}\": {}{}", key, value_str.to_ascii_lowercase(), comma)?;
        } else {
            writeln!(w, "  \"{}\": \"{}\"{}", key, escape_json(value_str), comma)?;
        }
    }
    write!(w, "}}")
}
