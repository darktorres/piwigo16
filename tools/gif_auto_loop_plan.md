# Plan: Auto-fix non-looping GIFs during sync

## Context
Animated GIFs can have a loop count baked into the file. A loop count of 0 means infinite loop; any other value (or a missing Netscape Application Extension block entirely) means the GIF plays a finite number of times and stops. Piwigo currently serves original GIF files as-is, so non-looping GIFs display as one-shot animations in the gallery. The sync process should automatically detect and fix this so all animated GIFs loop infinitely.

## Approach
Add pure-PHP GIF binary patching to `get_sync_metadata()` in the metadata sync phase. No external dependencies (no ImageMagick, no FFmpeg).

The GIF loop count is stored in the **Netscape Application Extension** block:
- Block signature: `\x21\xFF\x0BNETSCAPE2.0\x03\x01`
- Followed by 2 little-endian bytes = loop count (0 = infinite)
- If the block is absent entirely, the GIF plays once

Two cases to handle:
1. **Block present, count ≠ 0** → overwrite the 2 count bytes with `\x00\x00`
2. **Block absent** → inject the full block after the Global Color Table

## Critical file
- `admin/inc/functions_metadata_admin.php` — `get_sync_metadata()` function

## Implementation

### 1. Add `gif_ensure_infinite_loop(string $path): bool` to `functions_metadata_admin`

```php
private static function gif_ensure_infinite_loop(string $path): bool
{
    $data = file_get_contents($path);
    if ($data === false || strlen($data) < 13) {
        return false;
    }

    $sig = "\x21\xFF\x0BNETSCAPE2.0\x03\x01";
    $pos = strpos($data, $sig);

    if ($pos !== false) {
        $lo = ord($data[$pos + 16]);
        $hi = ord($data[$pos + 17]);
        if ($lo === 0 && $hi === 0) {
            return false; // already infinite
        }
        $data[$pos + 16] = "\x00";
        $data[$pos + 17] = "\x00";
    } else {
        // Inject Netscape block after Global Color Table
        $gct_flag = (ord($data[10]) & 0x80) !== 0;
        $gct_size = $gct_flag ? 3 * (2 ** ((ord($data[10]) & 0x07) + 1)) : 0;
        $insert_pos = 13 + $gct_size;
        $block = "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
        $data = substr($data, 0, $insert_pos) . $block . substr($data, $insert_pos);
    }

    file_put_contents($path, $data);
    return true;
}
```

### 2. Call from `get_sync_metadata()`

After the file extension is determined, before metadata extraction:

```php
if ($extension === 'gif') {
    self::gif_ensure_infinite_loop($path);
}
```

Already-infinite GIFs return early with no file I/O. Runs on every sync (new and existing files, since `get_sync_metadata` is called for all files needing a metadata refresh).

## Verification
1. Add a non-looping GIF to the gallery folder
2. Run sync from Admin → Site manager → Synchronize
3. `magick identify -verbose file.gif | grep -i iterations` → should show `0`
4. View thumbnail in browser — should loop
