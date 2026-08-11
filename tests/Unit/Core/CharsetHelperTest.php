<?php

declare(strict_types=1);

use Piwigo\Core\CharsetHelper;

/**
 * Piwigo\Core\CharsetHelper -- had zero dedicated coverage.
 *
 * getPwgCharset()'s `defined('PWG_CHARSET')` branch is deliberately NOT
 * exercised here: PWG_CHARSET is only ever define()'d as a string embedded
 * in InstallWizard's generated config-file content (never executed live in
 * this test process), so it stays genuinely undefined throughout a normal
 * test run -- and PHP constants can't be undefined once set, so defining
 * it here would permanently leak into every other test file sharing this
 * same process (Pest runs the whole suite in one process). Not worth that
 * risk for one branch.
 *
 * convertCharset()'s ext-mbstring fallback branch (`! function_exists
 * ('iconv')`) is similarly unreachable: both ext-iconv and ext-mbstring
 * are hard composer.json requirements, and ext-iconv is checked first, so
 * the mbstring fallback can never actually run here.
 *
 * A mutation-testing sweep found 12 mutations initially
 * showed UNTESTED, all inside convertCharset(). Each verified individually
 * via a live sed-applied mutation rerun (not assumed from reasoning
 * alone):
 *
 * - Line 31's RemoveEarlyReturn (the same-charset passthrough) wasn't
 *   caught by the original passthrough test because `iconv('utf-8',
 *   'utf-8//TRANSLIT', 'hello')` -- what real code would wrongly fall
 *   through to under the mutation -- happens to also return 'hello'
 *   unchanged for plain ASCII text: TRANSLIT only ever alters an input
 *   when the DESTINATION charset can't represent something, and utf-8
 *   can represent anything, so utf-8-to-utf-8 via iconv is an identity
 *   operation for any well-formed string. It stops being one for a
 *   MALFORMED utf-8 byte sequence, though (confirmed live: iconv()
 *   returns false on illegal input it's asked to "convert", even
 *   between identical charsets) -- see the new test below.
 * - Line 33's 2 IdenticalToNotIdentical mutations and its
 *   BooleanAndToBooleanOr mutation, plus line 34's RemoveEarlyReturn
 *   (all in the dedicated iso-8859-1-to-utf-8 branch), are genuinely,
 *   provably equivalent: confirmed via an exhaustive scan of all 256
 *   possible input bytes that `mb_convert_encoding($s, 'UTF-8',
 *   'ISO-8859-1')` and `iconv('iso-8859-1', 'utf-8//TRANSLIT', $s)`
 *   produce byte-identical output for every single one, with zero
 *   exceptions. This makes sense: ISO-8859-1 is a complete, unambiguous
 *   1:1 mapping from every byte to a Unicode codepoint, and utf-8 can
 *   represent all of them exactly -- there is no input for which
 *   TRANSLIT (which only ever kicks in when the DESTINATION can't
 *   represent a character) could cause iconv to diverge from
 *   mb_convert_encoding here. Since ISO-8859-1 has no invalid byte
 *   sequences either (unlike utf-8), there's no malformed-input trick
 *   available for this direction the way there was for line 31. Any
 *   mutation that only changes WHICH of these two functions gets
 *   called is unobservable for this specific charset pair, no matter
 *   what string is used.
 * - Line 36's 2 IdenticalToNotIdentical mutations, its
 *   BooleanAndToBooleanOr mutation, and line 37's RemoveEarlyReturn
 *   (the dedicated utf-8-to-iso-8859-1 branch) ARE observable, unlike
 *   the reverse direction above: ISO-8859-1 can't represent every
 *   Unicode codepoint, so mb_convert_encoding() (which substitutes an
 *   unmappable character with a literal '?') and iconv()'s TRANSLIT
 *   (which tries an actual transliteration first) genuinely diverge --
 *   confirmed live with the euro sign (U+20AC): mb_convert_encoding
 *   gives '?', iconv//TRANSLIT gives 'EUR'. See the new test below,
 *   which asserts the dedicated mb_convert_encoding branch's exact '?'
 *   output.
 * - Line 39's IfNegated and line 40's RemoveEarlyReturn (the generic
 *   iconv branch's own guard) weren't caught by the original "falls
 *   through to iconv" test because it only asserted "a string, not
 *   equal to the input" -- both iconv() and the mb_convert_encoding()
 *   fallback genuinely produce SOME different-from-input string for
 *   ascii-to-utf-16, so that loose assertion couldn't tell which one
 *   actually ran. Tightened below to an exact byte comparison: iconv()
 *   prepends a byte-order mark for UTF-16 (`fffe...`), confirmed live
 *   that mb_convert_encoding() does not -- a real, meaningful
 *   difference in the actual bytes written to a file/response, not
 *   just a test-detection convenience.
 * - Line 40's ConcatRemoveRight (dropping the `//TRANSLIT` suffix) is
 *   closed by the same euro-sign trick from the utf-8-to-iso-8859-1
 *   case above, this time against ascii (which, like iso-8859-1,
 *   can't represent the euro sign): with TRANSLIT, iconv genuinely
 *   transliterates it to 'EUR'; without TRANSLIT, confirmed live it
 *   returns false (a real, meaningful failure mode this project's own
 *   `string|false` return type exists to let callers detect).
 */
test('getPwgCharset defaults to utf-8 when PWG_CHARSET is not defined', function (): void {
    expect(defined('PWG_CHARSET'))
        ->toBeFalse();
    expect(CharsetHelper::getPwgCharset())->toBe('utf-8');
});

test('convertCharset is a passthrough when source and destination charsets match', function (): void {
    expect(CharsetHelper::convertCharset('hello', 'utf-8', 'utf-8'))->toBe('hello');
});

test('convertCharset passes a malformed utf-8 byte sequence through unchanged when source and destination charsets match, rather than mangling it via an unnecessary conversion attempt', function (): void {
    // Kills line 31's RemoveEarlyReturn: real code returns $str
    // immediately without ever touching iconv/mb_convert_encoding, so a
    // genuinely malformed byte sequence survives unchanged. Falling
    // through to the (redundant, since source === dest) iconv()
    // TRANSLIT call at line 40 instead -- what the mutant does --
    // returns false on illegal input, confirmed live.
    $malformed = "a\x80b";

    expect(CharsetHelper::convertCharset($malformed, 'utf-8', 'utf-8'))->toBe($malformed);
});

test('convertCharset converts iso-8859-1 to utf-8 via the dedicated mb_convert_encoding branch', function (): void {
    expect(CharsetHelper::convertCharset("caf\xe9", 'iso-8859-1', 'utf-8'))->toBe("caf\xc3\xa9");
});

test('convertCharset converts utf-8 to iso-8859-1 via the dedicated mb_convert_encoding branch', function (): void {
    expect(CharsetHelper::convertCharset("caf\xc3\xa9", 'utf-8', 'iso-8859-1'))->toBe("caf\xe9");
});

test('convertCharset converts utf-8 to iso-8859-1 using substitution, not transliteration, for a character iso-8859-1 cannot represent', function (): void {
    // Kills line 36's 2 IdenticalToNotIdentical mutations, its
    // BooleanAndToBooleanOr mutation, and line 37's RemoveEarlyReturn:
    // the dedicated mb_convert_encoding branch substitutes an
    // unmappable character with a literal '?'. Falling through to the
    // generic iconv//TRANSLIT branch instead -- what every one of
    // these mutations does -- would actually transliterate the euro
    // sign to 'EUR', a different, observably-longer result.
    $euroSign = "\xe2\x82\xac"; // U+20AC EURO SIGN, not representable in iso-8859-1

    expect(CharsetHelper::convertCharset($euroSign, 'utf-8', 'iso-8859-1'))->toBe('?');
});

test('convertCharset falls through to the generic iconv branch for any other charset pair, preserving the byte-order mark iconv adds and mb_convert_encoding does not', function (): void {
    // Kills line 39's IfNegated and line 40's RemoveEarlyReturn: the
    // original loose "not equal to input" assertion couldn't tell
    // apart the real iconv() branch from a wrongly-taken
    // mb_convert_encoding() fallback, since both produce SOME
    // different-from-input string here. iconv() genuinely prepends a
    // UTF-16 byte-order mark (0xFFFE); mb_convert_encoding() does not
    // -- confirmed live, a real difference in the actual output bytes.
    $result = CharsetHelper::convertCharset('hello', 'ascii', 'utf-16');

    expect($result)
        ->toBe("\xff\xfeh\x00e\x00l\x00l\x00o\x00");
});

test('convertCharset transliterates an unmappable character in the generic iconv branch rather than failing outright', function (): void {
    // Kills line 40's ConcatRemoveRight (dropping the //TRANSLIT
    // suffix): with TRANSLIT, iconv() approximates the euro sign as
    // the ASCII string 'EUR' (ascii, like iso-8859-1, can't represent
    // it directly). Without TRANSLIT -- what this mutation does --
    // confirmed live iconv() returns false instead, a real,
    // meaningful failure this project's own `string|false` return
    // type exists to let callers detect.
    $euroSign = "\xe2\x82\xac"; // U+20AC EURO SIGN, not representable in ascii

    expect(CharsetHelper::convertCharset($euroSign, 'utf-8', 'ascii'))->toBe('EUR');
});
