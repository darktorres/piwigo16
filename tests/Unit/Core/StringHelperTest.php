<?php

declare(strict_types=1);

use Piwigo\Core\StringHelper;

/**
 * Independent copies of removeAccents()'s own lookup tables (transcribed
 * from the current, trusted source once -- NOT derived via Reflection at
 * test time, which would make any assertion against them tautological
 * and unable to catch a single mutation). Used by the exhaustive-coverage
 * tests below to close every RemoveArrayItem/Concat/IncrementInteger/
 * DecrementInteger mutation pest --mutate found across removeAccents()'s
 * ~250 individual table entries -- see this file's own docblock below.
 */
const UTF8_FIXTURE = [
    "\xc3\x80" => 'A',
    "\xc3\x81" => 'A',
    "\xc3\x82" => 'A',
    "\xc3\x83" => 'A',
    "\xc3\x84" => 'A',
    "\xc3\x85" => 'A',
    "\xc3\x87" => 'C',
    "\xc3\x88" => 'E',
    "\xc3\x89" => 'E',
    "\xc3\x8a" => 'E',
    "\xc3\x8b" => 'E',
    "\xc3\x8c" => 'I',
    "\xc3\x8d" => 'I',
    "\xc3\x8e" => 'I',
    "\xc3\x8f" => 'I',
    "\xc3\x91" => 'N',
    "\xc3\x92" => 'O',
    "\xc3\x93" => 'O',
    "\xc3\x94" => 'O',
    "\xc3\x95" => 'O',
    "\xc3\x96" => 'O',
    "\xc3\x99" => 'U',
    "\xc3\x9a" => 'U',
    "\xc3\x9b" => 'U',
    "\xc3\x9c" => 'U',
    "\xc3\x9d" => 'Y',
    "\xc3\x9f" => 's',
    "\xc3\xa0" => 'a',
    "\xc3\xa1" => 'a',
    "\xc3\xa2" => 'a',
    "\xc3\xa3" => 'a',
    "\xc3\xa4" => 'a',
    "\xc3\xa5" => 'a',
    "\xc3\xa7" => 'c',
    "\xc3\xa8" => 'e',
    "\xc3\xa9" => 'e',
    "\xc3\xaa" => 'e',
    "\xc3\xab" => 'e',
    "\xc3\xac" => 'i',
    "\xc3\xad" => 'i',
    "\xc3\xae" => 'i',
    "\xc3\xaf" => 'i',
    "\xc3\xb1" => 'n',
    "\xc3\xb2" => 'o',
    "\xc3\xb3" => 'o',
    "\xc3\xb4" => 'o',
    "\xc3\xb5" => 'o',
    "\xc3\xb6" => 'o',
    "\xc3\xb9" => 'u',
    "\xc3\xba" => 'u',
    "\xc3\xbb" => 'u',
    "\xc3\xbc" => 'u',
    "\xc3\xbd" => 'y',
    "\xc3\xbf" => 'y',
    "\xc4\x80" => 'A',
    "\xc4\x81" => 'a',
    "\xc4\x82" => 'A',
    "\xc4\x83" => 'a',
    "\xc4\x84" => 'A',
    "\xc4\x85" => 'a',
    "\xc4\x86" => 'C',
    "\xc4\x87" => 'c',
    "\xc4\x88" => 'C',
    "\xc4\x89" => 'c',
    "\xc4\x8a" => 'C',
    "\xc4\x8b" => 'c',
    "\xc4\x8c" => 'C',
    "\xc4\x8d" => 'c',
    "\xc4\x8e" => 'D',
    "\xc4\x8f" => 'd',
    "\xc4\x90" => 'D',
    "\xc4\x91" => 'd',
    "\xc4\x92" => 'E',
    "\xc4\x93" => 'e',
    "\xc4\x94" => 'E',
    "\xc4\x95" => 'e',
    "\xc4\x96" => 'E',
    "\xc4\x97" => 'e',
    "\xc4\x98" => 'E',
    "\xc4\x99" => 'e',
    "\xc4\x9a" => 'E',
    "\xc4\x9b" => 'e',
    "\xc4\x9c" => 'G',
    "\xc4\x9d" => 'g',
    "\xc4\x9e" => 'G',
    "\xc4\x9f" => 'g',
    "\xc4\xa0" => 'G',
    "\xc4\xa1" => 'g',
    "\xc4\xa2" => 'G',
    "\xc4\xa3" => 'g',
    "\xc4\xa4" => 'H',
    "\xc4\xa5" => 'h',
    "\xc4\xa6" => 'H',
    "\xc4\xa7" => 'h',
    "\xc4\xa8" => 'I',
    "\xc4\xa9" => 'i',
    "\xc4\xaa" => 'I',
    "\xc4\xab" => 'i',
    "\xc4\xac" => 'I',
    "\xc4\xad" => 'i',
    "\xc4\xae" => 'I',
    "\xc4\xaf" => 'i',
    "\xc4\xb0" => 'I',
    "\xc4\xb1" => 'i',
    "\xc4\xb2" => 'IJ',
    "\xc4\xb3" => 'ij',
    "\xc4\xb4" => 'J',
    "\xc4\xb5" => 'j',
    "\xc4\xb6" => 'K',
    "\xc4\xb7" => 'k',
    "\xc4\xb8" => 'k',
    "\xc4\xb9" => 'L',
    "\xc4\xba" => 'l',
    "\xc4\xbb" => 'L',
    "\xc4\xbc" => 'l',
    "\xc4\xbd" => 'L',
    "\xc4\xbe" => 'l',
    "\xc4\xbf" => 'L',
    "\xc5\x80" => 'l',
    "\xc5\x81" => 'L',
    "\xc5\x82" => 'l',
    "\xc5\x83" => 'N',
    "\xc5\x84" => 'n',
    "\xc5\x85" => 'N',
    "\xc5\x86" => 'n',
    "\xc5\x87" => 'N',
    "\xc5\x88" => 'n',
    "\xc5\x89" => 'N',
    "\xc5\x8a" => 'n',
    "\xc5\x8b" => 'N',
    "\xc5\x8c" => 'O',
    "\xc5\x8d" => 'o',
    "\xc5\x8e" => 'O',
    "\xc5\x8f" => 'o',
    "\xc5\x90" => 'O',
    "\xc5\x91" => 'o',
    "\xc5\x92" => 'OE',
    "\xc5\x93" => 'oe',
    "\xc5\x94" => 'R',
    "\xc5\x95" => 'r',
    "\xc5\x96" => 'R',
    "\xc5\x97" => 'r',
    "\xc5\x98" => 'R',
    "\xc5\x99" => 'r',
    "\xc5\x9a" => 'S',
    "\xc5\x9b" => 's',
    "\xc5\x9c" => 'S',
    "\xc5\x9d" => 's',
    "\xc5\x9e" => 'S',
    "\xc5\x9f" => 's',
    "\xc5\xa0" => 'S',
    "\xc5\xa1" => 's',
    "\xc5\xa2" => 'T',
    "\xc5\xa3" => 't',
    "\xc5\xa4" => 'T',
    "\xc5\xa5" => 't',
    "\xc5\xa6" => 'T',
    "\xc5\xa7" => 't',
    "\xc5\xa8" => 'U',
    "\xc5\xa9" => 'u',
    "\xc5\xaa" => 'U',
    "\xc5\xab" => 'u',
    "\xc5\xac" => 'U',
    "\xc5\xad" => 'u',
    "\xc5\xae" => 'U',
    "\xc5\xaf" => 'u',
    "\xc5\xb0" => 'U',
    "\xc5\xb1" => 'u',
    "\xc5\xb2" => 'U',
    "\xc5\xb3" => 'u',
    "\xc5\xb4" => 'W',
    "\xc5\xb5" => 'w',
    "\xc5\xb6" => 'Y',
    "\xc5\xb7" => 'y',
    "\xc5\xb8" => 'Y',
    "\xc5\xb9" => 'Z',
    "\xc5\xba" => 'z',
    "\xc5\xbb" => 'Z',
    "\xc5\xbc" => 'z',
    "\xc5\xbd" => 'Z',
    "\xc5\xbe" => 'z',
    "\xc5\xbf" => 's',
    "\xc8\x98" => 'S',
    "\xc8\x99" => 's',
    "\xc8\x9a" => 'T',
    "\xc8\x9b" => 't',
    "\xe2\x82\xac" => 'E',
    "\xc2\xa3" => '',
];

const ISO_FIXTURE = [
    128 => 'E',
    131 => 'f',
    138 => 'S',
    142 => 'Z',
    154 => 's',
    158 => 'z',
    159 => 'Y',
    162 => 'c',
    165 => 'Y',
    181 => 'u',
    192 => 'A',
    193 => 'A',
    194 => 'A',
    195 => 'A',
    196 => 'A',
    197 => 'A',
    199 => 'C',
    200 => 'E',
    201 => 'E',
    202 => 'E',
    203 => 'E',
    204 => 'I',
    205 => 'I',
    206 => 'I',
    207 => 'I',
    209 => 'N',
    210 => 'O',
    211 => 'O',
    212 => 'O',
    213 => 'O',
    214 => 'O',
    216 => 'O',
    217 => 'U',
    218 => 'U',
    219 => 'U',
    220 => 'U',
    221 => 'Y',
    224 => 'a',
    225 => 'a',
    226 => 'a',
    227 => 'a',
    228 => 'a',
    229 => 'a',
    231 => 'c',
    232 => 'e',
    233 => 'e',
    234 => 'e',
    235 => 'e',
    236 => 'i',
    237 => 'i',
    238 => 'i',
    239 => 'i',
    241 => 'n',
    242 => 'o',
    243 => 'o',
    244 => 'o',
    245 => 'o',
    246 => 'o',
    248 => 'o',
    249 => 'u',
    250 => 'u',
    251 => 'u',
    252 => 'u',
    253 => 'y',
    255 => 'y',
];

const DOUBLE_FIXTURE = [
    140 => 'OE',
    156 => 'oe',
    198 => 'AE',
    208 => 'DH',
    222 => 'TH',
    223 => 'ss',
    230 => 'ae',
    240 => 'dh',
    254 => 'th',
];

test('getExtension returns the part after the last dot', function (): void {
    expect(StringHelper::getExtension('archive.tar.gz'))->toBe('gz');
    expect(StringHelper::getExtension('photo.JPG'))->toBe('JPG');
});

test('getExtension returns an empty string for null or a dot-less filename', function (): void {
    expect(StringHelper::getExtension(null))->toBe('');
    expect(StringHelper::getExtension('no_extension'))->toBe('');
});

test('getFilenameWoExtension returns everything before the last dot', function (): void {
    expect(StringHelper::getFilenameWoExtension('archive.tar.gz'))->toBe('archive.tar');
});

test('getFilenameWoExtension returns the filename unchanged when there is no dot', function (): void {
    expect(StringHelper::getFilenameWoExtension('no_extension'))->toBe('no_extension');
});

test('qualifyUtf8 returns 0 for a plain ASCII string', function (): void {
    expect(StringHelper::qualifyUtf8('Hello World 123'))->toBe(0);
});

test('qualifyUtf8 returns 1 for well-formed multi-byte UTF-8', function (): void {
    expect(StringHelper::qualifyUtf8("Caf\xc3\xa9"))->toBe(1);
});

test('qualifyUtf8 returns -1 for a malformed continuation byte', function (): void {
    // 0xC3 signals a 2-byte sequence, but is followed by a plain ASCII
    // byte instead of a 10bbbbbb continuation byte.
    expect(StringHelper::qualifyUtf8("\xc3A"))->toBe(-1);
});

test('qualifyUtf8 returns -1 when a multi-byte sequence is cut off at the end of the string', function (): void {
    expect(StringHelper::qualifyUtf8("Caf\xc3"))->toBe(-1);
});

test('qualifyUtf8 returns 1 for a well-formed 4-byte sequence (a real UTF-8 emoji)', function (): void {
    expect(StringHelper::qualifyUtf8("\xf0\x9f\x98\x80"))->toBe(1); // U+1F600
});

test('qualifyUtf8 returns 1 for well-formed 5-byte and 6-byte lead sequences', function (): void {
    // 0xF8-0xFB (5-byte) and 0xFC-0xFD (6-byte) lead bytes are outside
    // the RFC 3629 UTF-8 range (capped at 4 bytes) but are part of the
    // original, broader UTF-8 scheme this method still recognizes --
    // synthetic since no real character encodes to either width.
    expect(StringHelper::qualifyUtf8("\xf8\x88\x88\x88\x88"))->toBe(1)
        ->and(StringHelper::qualifyUtf8("\xfc\x88\x88\x88\x88\x88"))->toBe(1);
});

test('qualifyUtf8 returns -1 for a 5-byte sequence cut off before its continuation bytes complete', function (): void {
    expect(StringHelper::qualifyUtf8("\xf8\x88\x88"))->toBe(-1);
});

test('qualifyUtf8 returns -1 for a lead byte matching none of the recognized bit patterns', function (): void {
    // 0xFE is 11111110 -- fails every (ord & mask) === pattern check.
    expect(StringHelper::qualifyUtf8("\xfe"))->toBe(-1);
});

test('qualifyUtf8 treats the ASCII/multi-byte boundary bytes 0x7F and 0x80 correctly', function (): void {
    // Kills line 53's SmallerToSmallerOrEqual (`<=` instead of `<`) and
    // IncrementInteger (0x80 -> 0x81): both would wrongly treat a lone
    // 0x80 byte as ASCII (skip via `continue`, loop ends with $ret still
    // at its initial 0) instead of correctly falling through to "matches
    // no lead-byte pattern" and returning -1. Also kills DecrementInteger
    // (0x80 -> 0x7F): a lone 0x7F would wrongly be treated as a
    // multi-byte lead instead of plain ASCII.
    expect(StringHelper::qualifyUtf8(chr(0x7F)))->toBe(0);
    expect(StringHelper::qualifyUtf8(chr(0x80)))->toBe(-1);
});

test('qualifyUtf8 recognizes lead bytes whose low bit is set, not just the canonical zero-low-bit examples', function (): void {
    // Kills the IncrementInteger mutations on each length's bitmask
    // (0xF0->0xF1 at line 60, 0xF8->0xF9 at line 63) and both
    // Increment/DecrementInteger on line 69's 0xFE mask (->0xFF/->0xFD):
    // the existing well-formed-sequence tests above all happen to use
    // lead bytes with a zero low bit (0xE0, 0xF0, 0xF8, 0xFC), which a
    // widened-by-one-bit mask still matches by coincidence. A lead byte
    // with the low bit SET only matches the real, correct mask.
    expect(StringHelper::qualifyUtf8("\xe1\x80\x80"))->toBe(1); // 3-byte, lead 0xE1
    expect(StringHelper::qualifyUtf8("\xf1\x80\x80\x80"))->toBe(1); // 4-byte, lead 0xF1
    expect(StringHelper::qualifyUtf8("\xf9\x80\x80\x80\x80"))->toBe(1); // 5-byte, lead 0xF9
    expect(StringHelper::qualifyUtf8("\xfd\x80\x80\x80\x80\x80"))->toBe(1); // 6-byte, lead 0xFD
});

/**
 * Confirmed-equivalent mutations (verified via a live sed-applied
 * mutation rerun, not assumed):
 *
 * - Line 91's RemoveEarlyReturn (`return $string; // ascii` when
 *   qualifyUtf8() === 0): without it, execution falls through to the
 *   ISO-8859-1 `strtr()` branch instead of returning immediately. But
 *   every byte in that branch's own lookup table is >= 128 (0x80), and
 *   ASCII input is by definition only bytes 0-127 -- so strtr() finds
 *   no matches and leaves the string unchanged regardless, the exact
 *   same result as the real early return. Confirmed live across
 *   several ASCII strings with no observable difference.
 * - Line 94's GreaterToGreaterOrEqual (`>=` instead of `>`) and
 *   DecrementInteger (`> -1` instead of `> 0`) on `if ($utf > 0)`: with
 *   line 91's own early return intact, $utf can only ever be -1 or 1 by
 *   the time this line runs (0 always returns first) -- and `1 > 0`,
 *   `1 >= 0`, `1 > -1` all agree, as do `-1 > 0`, `-1 >= 0`, `-1 > -1`.
 *   No test can distinguish them because the one value that WOULD
 *   (0) never reaches this line.
 */
test('removeAccents leaves a plain ASCII string unchanged', function (): void {
    expect(StringHelper::removeAccents('Hello World'))->toBe('Hello World');
});

test('removeAccents strips accents from UTF-8 input', function (): void {
    expect(StringHelper::removeAccents("\xc3\x89t\xc3\xa9"))->toBe('Ete'); // "Été"
});

test('removeAccents handles the Euro and Pound signs specially', function (): void {
    expect(StringHelper::removeAccents("100\xe2\x82\xac"))->toBe('100E');
    expect(StringHelper::removeAccents("\xc2\xa310"))->toBe('10');
});

test('removeAccents transliterates ISO-8859-1 input via the non-UTF-8 branch', function (): void {
    // chr(233) is é in ISO-8859-1 -- a single byte >= 0x80 with no valid
    // UTF-8 continuation sequence, so qualifyUtf8() returns -1 for it.
    $iso8859_1_e_acute = chr(233);

    expect(StringHelper::removeAccents($iso8859_1_e_acute))->toBe('e');
});

test('removeAccents expands every ISO-8859-1 digraph (OE/AE/etc) to its 2-letter ASCII form, not just the 2 already spot-checked', function (): void {
    // Kills line 310's RemoveArrayItem mutations (double_chars['in']) and
    // line 311's (double_chars['out']): each drops one of the 9 digraph
    // entries entirely, leaving that specific byte untransliterated by
    // str_replace() instead of expanded to its 2-letter form.
    foreach (DOUBLE_FIXTURE as $byte => $expected) {
        expect(StringHelper::removeAccents(chr($byte)))->toBe($expected, "byte {$byte}");
    }
});

test('removeAccents transliterates every entry in its UTF-8 lookup table to its documented ASCII replacement', function (): void {
    // Kills all 188 RemoveArrayItem mutations across lines 97-288: each
    // one drops a single key => value pair from the $chars array used by
    // strtr(), leaving that one UTF-8 character passed through
    // unmodified instead of transliterated. UTF8_FIXTURE (top of file)
    // is an independent copy, not derived via Reflection, so it can
    // actually catch a divergence rather than trivially matching it.
    foreach (UTF8_FIXTURE as $char => $expected) {
        expect(StringHelper::removeAccents($char))->toBe($expected, 'char ' . bin2hex($char));
    }
});

test('removeAccents transliterates every entry in its ISO-8859-1 lookup table to its documented ASCII replacement', function (): void {
    // Kills line 295's 139 Concat mutations (ConcatSwitchSides/
    // ConcatRemoveRight reorder or truncate the chr()-concatenation
    // chain building $chars['in'], desyncing which byte maps to which
    // position in $chars['out']) and every IncrementInteger/
    // DecrementInteger on the individual chr() arguments across lines
    // 296-310 (each shifts one byte value by 1, so the real byte either
    // drops out of the map or a neighboring byte wrongly gains an entry).
    // Exhaustively testing every single mapped byte is the only way to
    // observe either kind of corruption; ISO_FIXTURE (top of file) is an
    // independent copy of the real byte->letter correspondence.
    foreach (ISO_FIXTURE as $byte => $expected) {
        expect(StringHelper::removeAccents(chr($byte)))->toBe($expected, "byte {$byte}");
    }
});

test('pwgTransliterate lowercases plain ASCII input', function (): void {
    expect(StringHelper::pwgTransliterate('HELLO'))->toBe('hello');
});

test('pwgTransliterate strips accents from already-lowercase UTF-8 input', function (): void {
    // Deliberately already-lowercase input: whether this environment's
    // mb_strtolower() path or the plain strtolower() fallback fires, a
    // lowercase accented character is a no-op for either lowercasing
    // path, so this assertion holds regardless of which branch this
    // environment takes.
    expect(StringHelper::pwgTransliterate("caf\xc3\xa9"))->toBe('cafe'); // "café"
});

test('str2url replaces spaces and punctuation with underscores, in lowercase', function (): void {
    expect(StringHelper::str2url('My Photo: Summer 2026'))->toBe('my_photo_summer_2026');
});

test('str2url falls back to the pre-strip transliteration when stripping empties the result', function (): void {
    // Every character is punctuation the URL-safe regex strips outright,
    // leaving an empty $res -- falls back to $safe (the merely-
    // transliterated, not-yet-stripped string) instead of returning ''.
    expect(StringHelper::str2url('!!!'))->toBe('!!!');
});

test('str2url trims leading/trailing whitespace before collapsing runs, not just internally', function (): void {
    // Kills line 337's UnwrapTrim: trim() runs on the innermost call,
    // BEFORE the collapse-regex, so leading/trailing whitespace is
    // removed outright. Without it -- what this mutation does -- the
    // collapse-regex only ever shrinks a whitespace RUN to a single
    // space (it's already a single space here), leaving stray leading/
    // trailing spaces that the final str_replace(' ', '_', ...) then
    // turns into stray leading/trailing underscores.
    expect(StringHelper::str2url(' hello '))->toBe('hello');
});

test('str2url\'s stripping-empties-the-result fallback still replaces spaces with underscores, not just returning $safe verbatim', function (): void {
    // Kills line 342's UnwrapStrReplace: '!!! !!!' strips down to a lone
    // space (trim()'d away to '' before the underscore-replacement
    // runs), triggering the fallback -- real code still runs
    // str_replace(' ', '_', $safe) on the pre-strip string, so the
    // internal space becomes an underscore. The existing '!!!' fallback
    // test above can't catch this: it has no space in $safe to begin
    // with, so str_replace(' ', '_', $safe) is a no-op either way.
    expect(StringHelper::str2url('!!! !!!'))->toBe('!!!_!!!');
});

/**
 * Confirmed-equivalent: line 337's RemoveStringCast (`trim($str)`
 * instead of `trim((string) $str)`) -- $str at this point is always the
 * genuine string result of the immediately-preceding preg_replace()
 * call on a string subject with non-`u`-modifier, non-catastrophic-
 * backtracking patterns, which can only return string|null for a
 * string subject and has no realistic way to return null here. The
 * cast exists to satisfy PHPStan's own conservative preg_replace()
 * stub, not to handle a reachable runtime case -- confirmed live: both
 * str2url tests above pass identically with the cast removed.
 */
test('getNameFromFile strips the extension and replaces underscores with spaces', function (): void {
    expect(StringHelper::getNameFromFile('my_holiday_photo.jpg'))->toBe('my holiday photo');
});
