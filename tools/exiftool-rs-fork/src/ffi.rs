//! In-process C ABI for `Piwigo\Metadata\ExifTool\ExifToolFfi` -- no
//! subprocess, no `-stay_open` protocol, no polling. See this crate's
//! top-level README.md for the benchmark that motivated this and the
//! crash-isolation tradeoff it accepts (a bad read takes down the calling
//! PHP process with it, unlike the old subprocess design's transparent
//! crash-detect-and-restart).
//!
//! Reuses [`crate::json_output::write_json_tags`] -- the exact same
//! priority-dedup, per-tag numeric selection, and array/scalar JSON
//! serialization the CLI's own `-j` output uses -- so this module can't
//! drift into a second, subtly different implementation of that logic the
//! way `-stay_open` mode once did (see the README's bugs 4-6).

use crate::exiftool::{ExifTool, Options};
use crate::json_output::write_json_tags;
use std::ffi::{CStr, CString};
use std::os::raw::c_char;

/// Frees a string previously returned by [`exiftool_rs_extract`].
#[unsafe(no_mangle)]
pub extern "C" fn exiftool_rs_free(ptr: *mut c_char) {
    if ptr.is_null() {
        return;
    }
    unsafe {
        drop(CString::from_raw(ptr));
    }
}

/// Extracts `tags_csv` (comma-separated `-TAG`/`-GROUP:TAG` requests, `#`
/// suffix supported, no leading `-`) from `path`, returning a JSON object
/// string (`{"SourceFile": ..., "Tag": value, ...}`) -- the same shape
/// `ExifToolProcess`'s old subprocess protocol produced per file, so
/// `ExifToolFfi::read()` can `json_decode()` it directly with no `[0]`
/// indexing (this is a single, synchronous, one-file call -- there's no
/// `-execute<N>`/`{readyN}` batching to disambiguate). Caller must free the
/// result via [`exiftool_rs_free`]. Returns an empty string on any read
/// error (matching `ExifToolProcess::read()`'s existing `null`-on-failure
/// contract once decoded).
#[unsafe(no_mangle)]
pub extern "C" fn exiftool_rs_extract(
    path_ptr: *const c_char,
    tags_csv_ptr: *const c_char,
) -> *mut c_char {
    let path = unsafe { CStr::from_ptr(path_ptr) }.to_string_lossy().into_owned();
    let tags_csv = unsafe { CStr::from_ptr(tags_csv_ptr) }
        .to_string_lossy()
        .into_owned();

    let mut options = Options::default();
    options.duplicates = true; // matches ExifToolProcess::doRead()'s own always-sent -a
    for raw in tags_csv.split(',').filter(|s| !s.is_empty()) {
        let mut tag_req = raw.to_string();
        if let Some(stripped) = tag_req.strip_suffix('#') {
            let bare = stripped.rsplit(':').next().unwrap_or(stripped);
            options.numeric_tags.insert(bare.to_lowercase());
            tag_req = stripped.to_string();
        }
        options.requested_tags.push(tag_req);
    }

    let numeric_tags = options.numeric_tags.clone();
    let et = ExifTool::with_options(options);
    let output = match et.extract_info(&path) {
        Ok(tags) => {
            let mut buf: Vec<u8> = Vec::new();
            // show_groups=false, group_family=0 (unused when show_groups is
            // false), dedup=true (ExifToolFfi never requests -ee), lang=None
            // (Piwigo never passes -lang) -- the same fixed values
            // ExifToolProcess's own tag requests always imply.
            let _ = write_json_tags(&mut buf, &tags, &path, false, false, 0, true, None, &numeric_tags);
            String::from_utf8(buf).unwrap_or_default()
        }
        Err(_) => String::new(),
    };

    CString::new(output).unwrap_or_default().into_raw()
}
