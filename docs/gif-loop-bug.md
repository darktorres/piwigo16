# GIF Loop Bug Investigation

## Symptom

Some GIFs in the gallery play once and stop instead of looping indefinitely.

Reported file: `galleries/tumblr/ai-link-again/tumblr_ai-link-again_811028728418271232_01.gif`

## Root Cause

The affected GIFs are encoded as **GIF87a** (the 1987 spec) without a
**NETSCAPE Application Extension** block. That block — introduced in GIF89a —
is how browsers are told to loop an animation (loop count `0` = infinite).
When it is absent, the spec says: play once and stop. Modern browsers comply.

### File audit — `tumblr/ai-link-again` album

| File | Header | Loop block | Loops? |
|---|---|---|---|
| `…_811028728418271232_01.gif` | GIF87a | absent | **no** |
| `…_812271000222892032_01.gif` | GIF87a | present (count=0) | yes |
| `…_813014292366573568_01.gif` | GIF87a | absent | **no** |
| all others | GIF89a | present (count=0) | yes |

Two files are affected. A third GIF87a file (`812271000222892032`) has the
NETSCAPE block injected by whatever tool originally created it, so browsers
still loop it.

## Why Piwigo Doesn't Fix It

Piwigo explicitly skips Imagick/ext-Imagick for GIFs and falls back to PHP GD
(`admin/inc/pwg_image.php:407,415`). PHP GD's `imagecreatefromgif()` reads
only the **first frame** — it cannot round-trip an animated GIF at all, let
alone preserve or add loop metadata. This means Piwigo cannot fix the loop
count when generating derivatives.

Because these GIFs fit inside the configured thumbnail size limits the
derivative is served as-is from the original file, so the broken loop count
reaches the browser unchanged.

## Fix Options

### A — Patch the source files (one-time)

Inject a NETSCAPE Application Extension block with loop count `0` into each
affected GIF. The block is 19 bytes inserted after the Logical Screen
Descriptor. This converts the file from GIF87a to GIF89a in-place.

A tool like `gifsicle` does this in one command:

```sh
gifsicle --batch --loopcount=forever \
  galleries/tumblr/ai-link-again/tumblr_ai-link-again_811028728418271232_01.gif \
  galleries/tumblr/ai-link-again/tumblr_ai-link-again_813014292366573568_01.gif
```

### B — Sync-time fix in Piwigo

During file sync, detect GIF87a files without a NETSCAPE loop block and inject
it then. This would catch any future uploads with the same issue.

### C — JavaScript workaround (not recommended)

Detect when a GIF's animation ends (`img` does not fire an `animationend`
event, so this requires canvas-based frame inspection) and reset `img.src` to
restart it. Fragile and adds unnecessary client-side weight.

## Recommendation

Option A with `gifsicle` for the two known files. Add a note to the sync
pipeline (Option B) if more GIF87a uploads are expected.
