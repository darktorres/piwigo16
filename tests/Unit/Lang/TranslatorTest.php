<?php

declare(strict_types=1);

use Gettext\Translation;
use Gettext\Translations;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Lang\Translator;

beforeEach(function (): void {
    // Each test constructs its own fresh instance directly -- no
    // reset()-between-tests machinery needed (same test-isolation shape
    // as CoreTabsTest/SectionContextRegistryTest's own precedent).
    $this->translator = new Translator(CurrentConfigTestFactory::get());
    $this->poFile = sys_get_temp_dir() . '/piwigo-po-test-' . bin2hex(random_bytes(8)) . '.po';
});

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
    if (file_exists($this->poFile)) {
        unlink($this->poFile);
    }
});

test('translate returns the original key when no PO file is loaded', function (): void {
    expect($this->translator->translate('Hello'))->toBe('Hello');
});

test('load parses a PO file and translate resolves the matching string', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "Hello"
        msgstr "Bonjour"
        PO);

    $this->translator->load('fr', $this->poFile);

    expect($this->translator->translate('Hello'))->toBe('Bonjour');
});

test('translate falls back to the mirrored string map for keys with no PO entry', function (): void {
    $this->translator->loadArray(['plugin_key' => 'plugin value']);

    expect($this->translator->translate('plugin_key'))->toBe('plugin value');
});

test('translate applies sprintf-style args', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "Hello %s"
        msgstr "Bonjour %s"
        PO);

    $this->translator->load('fr', $this->poFile);

    expect($this->translator->translate('Hello %s', 'World'))->toBe('Bonjour World');
});

// Confirmed-equivalent: RemoveEarlyReturn on plural()'s `return sprintf(
// $val, $n);` early-return (only reachable when $args === []) turns it
// into a no-op, falling through to `array_map(..., $args)` (trivially []
// since $args is []) then `return vsprintf($val, [$n, ...[]]);` --
// vsprintf($val, [$n]) and sprintf($val, $n) are PHP's own array/variadic
// forms of the exact same formatting call, guaranteed identical for any
// $val/$n (verified: both produce byte-identical output across ints,
// floats, bools, null, and PHP_INT_MAX). Verified live: mutated the return
// into the removal, reran this whole file, all tests -- including both
// plural() tests below that hit the $args === [] path -- passed unchanged,
// restored byte-identical (diff clean).
test('plural picks the correct form for a 2-form language', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "%d photo"
        msgid_plural "%d photos"
        msgstr[0] "%d photo"
        msgstr[1] "%d photos"
        PO);

    $this->translator->load('en', $this->poFile);

    expect($this->translator->plural('%d photo', '%d photos', 1))->toBe('1 photo')
        ->and($this->translator->plural('%d photo', '%d photos', 5))->toBe('5 photos');
});

// Confirmed-equivalent: toDictionaryEntry()'s `$forms` array (built at
// line 210-212, either `[$singularForm, ...$entry->getPluralTranslations()]`
// or `[$singularForm]`) is normalized at line 214-216 via
// `array_values(array_map(fn (mixed $f): string => is_string($f) ? $f :
// '', $forms))`. Both the UnwrapArrayValues (214) and the closure's
// EmptyStringToNotEmpty/UnwrapArrayMap (215) mutations are no-ops given
// $forms's elements: $singularForm always comes from `getTranslation() ??
// ''` (always a real `string`), and every element `...
// $entry->getPluralTranslations()` spreads in is guaranteed `string` by
// Translation::translatePlural(string ...$translations)'s own variadic
// parameter type (the array is only ever built by that method, or copied
// from another instance's own already-conforming array via mergeWith()) --
// so `is_string($f)` is a tautology (the '' fallback branch is dead code
// regardless of which literal it holds) and the closure is provably
// identical to the identity function, making array_map(identity, $forms)
// === $forms and array_values() on an already-list-shaped array (array
// literals with plain items/spread are always sequential from 0) a no-op.
// Verified live: mutated all three sites in turn (Python-applied AST-
// equivalent edits), reran this whole file -- including the 3-form test
// below, which exercises the multi-element `[$singularForm, ...]` branch
// -- all passed unchanged each time, restored byte-identical (diff clean).
test('plural picks the correct form for a 3-form language (Russian-style rule)', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2);\n"

        msgid "%d photo"
        msgid_plural "%d photos"
        msgstr[0] "%d photo-one"
        msgstr[1] "%d photo-few"
        msgstr[2] "%d photo-many"
        PO);

    $this->translator->load('ru', $this->poFile);

    expect($this->translator->plural('%d photo', '%d photos', 1))->toBe('1 photo-one')
        ->and($this->translator->plural('%d photo', '%d photos', 2))->toBe('2 photo-few')
        ->and($this->translator->plural('%d photo', '%d photos', 11))->toBe('11 photo-many');
});

test('load mirrors translations into mirroredStrings(), translate()\'s own fallback', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "Hello"
        msgstr "Bonjour"

        msgid "%d photo"
        msgid_plural "%d photos"
        msgstr[0] "%d photo"
        msgstr[1] "%d photos"
        PO);

    $this->translator->load('fr', $this->poFile);

    $mirror = $this->translator->mirroredStrings();
    expect($mirror['Hello'])->toBe('Bonjour')
        ->and($mirror['%d photo'])->toBe('%d photo')
        ->and($mirror['%d photos'])->toBe('%d photos');
});

test('load on an unreadable file is a silent no-op', function (): void {
    $this->translator->load('fr', '/nonexistent/path.po');

    expect($this->translator->translate('Hello'))->toBe('Hello');
});

test('translate warns about a missing key when debug_l10n is enabled', function (): void {
    CurrentConfigTestFactory::get()->setDebugL10n(true);

    $triggered = null;
    set_error_handler(function (int $errno, string $errstr) use (&$triggered): bool {
        $triggered = $errstr;
        return true;
    }, E_USER_WARNING);

    $this->translator->translate('missing_key');

    restore_error_handler();

    expect($triggered)->toBe('[l10n] language key "missing_key" not defined');
});

test('translate does not warn about a missing key when debug_l10n is disabled', function (): void {
    CurrentConfigTestFactory::get()->setDebugL10n(false);

    $triggered = false;
    set_error_handler(function () use (&$triggered): bool {
        $triggered = true;
        return true;
    }, E_USER_WARNING);

    $this->translator->translate('missing_key');

    restore_error_handler();

    expect($triggered)->toBeFalse();
});

test('translate does not warn about a resolved key even when debug_l10n is enabled', function (): void {
    CurrentConfigTestFactory::get()->setDebugL10n(true);
    $this->translator->loadArray(['known_key' => 'known value']);

    $triggered = false;
    set_error_handler(function () use (&$triggered): bool {
        $triggered = true;
        return true;
    }, E_USER_WARNING);

    $result = $this->translator->translate('known_key');

    restore_error_handler();

    expect($result)->toBe('known value')
        ->and($triggered)->toBeFalse();
});

test('restoreFrom() copies a snapshot instance\'s translation state onto this instance, in place', function (): void {
    $this->translator->loadArray(['original_key' => 'original value']);
    $snapshot = clone $this->translator;

    // Mutate the live instance further after taking the snapshot -- proves
    // restoreFrom() below reverts to the snapshot's own state, not just
    // whatever the live instance already happened to hold.
    $this->translator->loadArray(['later_key' => 'later value']);

    $restored = new Translator(CurrentConfigTestFactory::get());
    $restored->restoreFrom($snapshot);

    expect($restored->translate('original_key'))->toBe('original value')
        ->and($restored->mirroredStrings())->not->toHaveKey('later_key');
});

test('plural applies sprintf-style args after the count', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "%d photo by %s"
        msgid_plural "%d photos by %s"
        msgstr[0] "%d photo by %s"
        msgstr[1] "%d photos by %s"
        PO);

    $this->translator->load('en', $this->poFile);

    expect($this->translator->plural('%d photo by %s', '%d photos by %s', 1, 'Alice'))->toBe('1 photo by Alice')
        ->and($this->translator->plural('%d photo by %s', '%d photos by %s', 3, 'Bob'))->toBe('3 photos by Bob');
});

// A msgctxt-tagged entry with an empty msgid is a real, loadable PO shape
// (confirmed empirically against the installed gettext/gettext PoLoader --
// distinct from the file-level header, which is the *context-less* empty
// msgid) that produces a Translation with getOriginal() === '' but a real
// context and translation string. Both toDictionaryEntry() and mirror()
// skip it via their own `$original === ''` guard -- this single load()
// call exercises both continue branches at once, since they iterate the
// same Translations collection.
//
// The sibling `! ($entry instanceof Translation)` guard in both of those
// same foreach loops is NOT exercised anywhere: Gettext\Translations::
// $translations is only ever populated through add()/addOrMerge(), both
// typed to accept a Translation and nothing else, so every element
// getTranslations() can ever yield is genuinely guaranteed to already be
// a Translation instance -- confirmed by reading vendor/gettext/gettext's
// own Translations class. There is no legitimate PO file or public API
// call that reaches that branch; it's unreachable defensive code, not a
// real gap.
//
// Confirmed-equivalent: InstanceOfToTrue on toDictionaryEntry()'s own
// `$entry instanceof Translation` (line 197) and mirror()'s identical
// check (line 242) follow from the exact same guarantee -- the check's
// truth value is already `true` for every real element, so collapsing it
// to the literal `true` changes nothing observable. Verified live: mutated
// each site in turn with a Python-applied AST-equivalent edit, reran this
// whole file, both passed unchanged, restored byte-identical (diff clean).
test('load skips a context-tagged entry whose msgid is empty', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgctxt "empty-original-context"
        msgid ""
        msgstr "should never surface"

        msgid "Hello"
        msgstr "Bonjour"
        PO);

    $this->translator->load('fr', $this->poFile);

    $mirror = $this->translator->mirroredStrings();
    expect($mirror)->not->toHaveKey('')
        ->and($mirror['Hello'])->toBe('Bonjour')
        ->and($this->translator->translate('Hello'))->toBe('Bonjour');
});

test('translate does not warn about a missing key when the key itself is empty', function (): void {
    CurrentConfigTestFactory::get()->setDebugL10n(true);

    $triggered = false;
    set_error_handler(function () use (&$triggered): bool {
        $triggered = true;
        return true;
    }, E_USER_WARNING);

    $this->translator->translate('');

    restore_error_handler();

    expect($triggered)->toBeFalse();
});

// $args's elements are mapped through `is_scalar($a) || $a === null ?
// (string) $a : ''` before being handed to vsprintf(). A non-scalar,
// non-null arg (e.g. an array) is the only input that distinguishes every
// step of that mapping: unmapped (UnwrapArrayMap) it would leak the raw
// array straight into vsprintf() (an "Array to string conversion" and a
// literal "Array" in the output); with the '' fallback swapped for a
// sentinel (EmptyStringToNotEmpty) the sentinel would surface instead of
// ''; and with the null-check flipped (IdenticalToNotIdentical) an array
// stops being null, flips the ternary to the (string) cast branch, and
// hits the same "Array to string conversion" leak.
test('translate maps a non-scalar arg to an empty string instead of leaking it into vsprintf', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "Hello %s"
        msgstr "Bonjour %s"
        PO);

    $this->translator->load('fr', $this->poFile);

    expect($this->translator->translate('Hello %s', ['nested']))->toBe('Bonjour ');
});

// Same mapping, same reasoning, for plural()'s own copy of the closure.
test('plural maps a non-scalar arg to an empty string instead of leaking it into vsprintf', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "%d photo by %s"
        msgid_plural "%d photos by %s"
        msgstr[0] "%d photo by %s"
        msgstr[1] "%d photos by %s"
        PO);

    $this->translator->load('en', $this->poFile);

    expect($this->translator->plural('%d photo by %s', '%d photos by %s', 3, ['nested']))->toBe('3 photos by ');
});

// The closure that builds $scalarArgs is declared `: string` inside this
// file's declare(strict_types=1) -- strict return-type checking applies to
// a function's own return statements based on the file it's DEFINED in,
// regardless of caller. If RemoveStringCast dropped the `(string)` cast,
// returning a non-string scalar (e.g. this int arg) straight from the
// `mixed` ternary branch would throw a TypeError instead of coercing.
test('plural casts a non-string scalar arg to string instead of returning it raw', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "%d item(s) of size %s"
        msgid_plural "%d items of size %s"
        msgstr[0] "%d item(s) of size %s"
        msgstr[1] "%d items of size %s"
        PO);

    $this->translator->load('en', $this->poFile);

    expect($this->translator->plural('%d item(s) of size %s', '%d items of size %s', 3, 42))->toBe('3 items of size 42');
});

// A context-less empty-original Translation is not producible through a
// real PO file (see the comment above the msgctxt test further up): the
// only such entry PoLoader ever emits is the file-level header, which is
// consumed into getHeaders() and never reaches getTranslations(). Testing
// toDictionaryEntry()'s own `$original === ''` skip (line 202) and its
// `continue` (not `break`, line 203) therefore requires building the
// Translations object directly and invoking the private method via
// Reflection -- the one otherwise-unreachable case in this file.
test('toDictionaryEntry skips only the empty-original entry, not the rest of the loop', function (): void {
    $translations = Translations::create();

    $emptyOriginal = Translation::create(null, '');
    $normal = Translation::create(null, 'Hello');
    $normal->translate('Bonjour');

    $translations->add($emptyOriginal);
    $translations->add($normal);

    $method = new ReflectionMethod(Translator::class, 'toDictionaryEntry');
    $result = $method->invoke(new Translator(CurrentConfigTestFactory::get()), $translations);
    if (! is_array($result)) {
        throw new RuntimeException('expected toDictionaryEntry() to return an array');
    }

    expect($result['messages'])->toBe(['' => ['Hello' => ['Bonjour']]]);
});

// Same private method, isolating the 'domain' entry's `getDomain() ?? ''`
// fallback (line 220) when no domain was declared at all. Not reachable
// through translate()/plural(): a single load() call is self-consistent
// regardless of which placeholder the fallback holds (storage and lookup
// derive from the very same local value), so this is checked directly on
// toDictionaryEntry()'s own return shape via Reflection instead.
test('toDictionaryEntry defaults the domain entry to an empty string when the PO file declares none', function (): void {
    $method = new ReflectionMethod(Translator::class, 'toDictionaryEntry');
    $result = $method->invoke(new Translator(CurrentConfigTestFactory::get()), Translations::create());
    if (! is_array($result)) {
        throw new RuntimeException('expected toDictionaryEntry() to return an array');
    }

    expect($result['domain'])->toBe('');
});

// A msgctxt-tagged entry sharing its msgid with a context-less entry
// exercises toDictionaryEntry()'s `$context = $entry->getContext() ?? '';`
// (line 206): unmutated, the two land in separate buckets ('' and
// 'some_ctx') of the dictionary GettextTranslator builds, so only the
// context-less one is reachable through translate() (which never passes a
// context). If CoalesceRemoveLeft forced $context to always be '', the
// second entry would overwrite the first in the same '' bucket instead.
test('load keeps a context-tagged translation out of the context-less bucket translate() actually reads', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "Duplicate"
        msgstr "NoContext"

        msgctxt "some_ctx"
        msgid "Duplicate"
        msgstr "WithContext"
        PO);

    $this->translator->load('fr', $this->poFile);

    expect($this->translator->translate('Duplicate'))->toBe('NoContext');
});

// A PO entry with no msgstr line at all (malformed but tolerated by
// PoLoader -- translate() is simply never called on the Translation, so
// getTranslation() stays null) exercises `$singularForm = $entry->
// getTranslation() ?? '';` (line 208): unmutated, the empty-string
// fallback keeps the dictionary slot empty, so gettext() falls through to
// the untranslated original. Also confirms mirror()'s own sibling guard
// (line 252's `$str !== null`) keeps this same null out of the mirror.
test('load treats a PO entry with no msgstr line as untranslated, in both the dictionary and the mirror', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "NoMsgstr"
        PO);

    $this->translator->load('en', $this->poFile);

    expect($this->translator->translate('NoMsgstr'))->toBe('NoMsgstr')
        ->and($this->translator->mirroredStrings())->not->toHaveKey('NoMsgstr');
});

// mirror()'s `$str !== null && $str !== ''` (line 252) has two guards: this
// covers the `$str !== ''` half with an explicitly empty (not absent)
// msgstr -- distinct from the NoMsgstr case above, which covers the
// `$str !== null` half.
test('load does not mirror an explicitly empty translation', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "EmptyTranslation"
        msgstr ""
        PO);

    $this->translator->load('en', $this->poFile);

    expect($this->translator->mirroredStrings())->not->toHaveKey('EmptyTranslation');
});

// A msgstr[1] present without a matching msgid_plural is a real (if
// unusual) PO shape PoLoader tolerates: translatePlural() and setPlural()
// are independent calls. This exercises toDictionaryEntry()'s ternary
// condition `$plural !== null && $plural !== ''` (line 210): unmutated,
// $plural stays null (no msgid_plural), so $forms is the singular-only
// `[$singularForm]` and the msgstr[1] text never reaches the dictionary --
// n=2 (plural index 1) then falls through to plural()'s own $plural
// fallback param, not the PO's msgstr[1].
test('plural ignores a msgstr[1] translation when no msgid_plural was declared', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "OddOne"
        msgstr "OddOneTranslated"
        msgstr[1] "ShouldNotAppear"
        PO);

    $this->translator->load('en', $this->poFile);

    expect($this->translator->plural('OddOne', 'fallback text', 2))->toBe('fallback text');
});

// Same shape, but with msgid_plural explicitly declared empty ("") rather
// than absent -- isolates the `$plural !== ''` half of line 210's
// condition from the `$plural !== null` half the test above covers.
test('plural ignores a msgstr[1] translation when msgid_plural is explicitly empty', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "EmptyPluralOne"
        msgid_plural ""
        msgstr "EmptyPluralOneTranslated"
        msgstr[1] "ShouldNotAppearEither"
        PO);

    $this->translator->load('en', $this->poFile);

    expect($this->translator->plural('EmptyPluralOne', 'fallback text2', 2))->toBe('fallback text2');
});

// plural() never consults $mirror (only translate() does), so unlike a
// translate()-based test, this cleanly exercises toDictionaryEntry()'s
// `: [$singularForm];` branch (line 212, taken when no plural was
// declared) at the GettextTranslator dictionary layer itself: if
// RemoveArrayItem emptied that array, the dictionary slot would have no
// index 0, and ngettext() would fall through to plural()'s own fallback
// param instead of the real translation.
test('plural resolves a singular-only translation directly from the dictionary (bypasses the mirror fallback)', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "Solo"
        msgstr "SoloTranslated"
        PO);

    $this->translator->load('en', $this->poFile);

    expect($this->translator->plural('Solo', 'unused-plural-fallback', 1))->toBe('SoloTranslated');
});

// Same mirror-bypass reasoning as the Solo test above, applied to
// toDictionaryEntry()'s 'domain' entry (line 220): the first load() call
// (no X-Domain) fixes the default lookup domain at ''; a second load()
// declaring an explicit X-Domain stores its translations under a
// *different* dictionary bucket that the default-domain lookup translate()
// /plural() always use can never reach. If CoalesceRemoveLeft forced the
// second file's domain to always be '' regardless of its real X-Domain
// header, its translations would merge into the default '' bucket instead
// and become reachable -- and RemoveArrayItem (dropping the whole 'domain'
// => ... entry so `$translations['domain'] ?? ''` silently defaults to '')
// produces the exact same observable collision.
test("load keeps a second PO file's explicit X-Domain out of the default lookup domain", function (): void {
    $secondPoFile = sys_get_temp_dir() . '/piwigo-po-test-' . bin2hex(random_bytes(8)) . '.po';

    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "First"
        msgstr "FirstVal"
        PO);

    file_put_contents($secondPoFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"
        "X-Domain: myplugin\n"

        msgid "SecondKey"
        msgstr "SecondVal"
        PO);

    $this->translator->load('en', $this->poFile);
    $this->translator->load('en', $secondPoFile);

    expect($this->translator->plural('SecondKey', 'fallback-text', 1))->toBe('SecondKey');

    unlink($secondPoFile);
});

// php-to-po-fn.php's own piwigo_day_N/piwigo_month_N flattening (see this
// class's own docblock) round-tripped back into mirror()'s nested 'day'/
// 'month' arrays. Exercises both preg_match(...) === 1 checks (lines 255
// and 257 -- IncrementInteger would make either always false, since
// preg_match() never returns 2) and both `if ($days/$months !== [])`
// guards (lines 280/283 -- IfNegated and NotIdenticalToIdentical both
// collapse to the same `=== []` inversion here, since neither has an
// else branch) at once: real day/month entries make both $days and
// $months non-empty, so all four mutations would leave mirroredStrings()
// missing (or empty at) 'day'/'month'. Both indexes are deliberately
// non-zero for at least one entry each (day uses 0 AND 1, month uses 5
// alone): DecrementInteger on `$matches[1]` -> `$matches[0]` (the full,
// non-numeric-leading regex match, e.g. "piwigo_month_5") would silently
// (int)-cast to 0 too -- indistinguishable from the real index only when
// the real index also happens to be 0, confirmed live (an earlier
// "piwigo_month_0"-only fixture didn't kill this mutation at line 258).
test("load reassembles piwigo_day_N/piwigo_month_N entries into mirroredStrings()'s nested day/month arrays", function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "piwigo_day_0"
        msgstr "Sunday"

        msgid "piwigo_day_1"
        msgstr "Monday"

        msgid "piwigo_month_5"
        msgstr "June"
        PO);

    $this->translator->load('en', $this->poFile);

    $mirror = $this->translator->mirroredStrings();
    expect($mirror['day'])->toBe([0 => 'Sunday', 1 => 'Monday'])
        ->and($mirror['month'])->toBe([5 => 'June']);
});

// Lines 256/258's own RemoveIntegerCast on `(int) $matches[1]` is
// confirmed-equivalent: preg_match()'s `(\d+)` capture group only ever
// yields pure-digit strings, and PHP's own array-key normalization
// already coerces a pure-digit string key to int identically to an
// explicit cast (`$arr['5']` and `$arr[(int) '5']` land on the same real
// int key 5, confirmed via a standalone probe). Live-verified: mutated
// line 258's cast away, the test above still passed unchanged, restored
// byte-identical.

// mirror()'s plural-original guard (line 275) chains four checks:
// `$pluralOriginal !== null && $pluralOriginal !== '' && is_string(
// $pluralForm0) && $pluralForm0 !== ''`. This entry (msgstr[1] present,
// no msgid_plural declared -- same real-but-unusual PO shape as the
// "OddOne" plural() test above) makes $pluralOriginal null: unmutated,
// the first `&&` short-circuits and nothing is mirrored under the
// (PHP-coerced) '' key. BooleanAndToBooleanOr on that first `&&` would
// let `null !== ''` (true) carry the whole chain instead.
test('load does not mirror a plural translation when no msgid_plural was declared', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "Alpha"
        msgstr "AlphaTranslated"
        msgstr[1] "AlphaPluralForm"
        PO);

    $this->translator->load('en', $this->poFile);

    expect($this->translator->mirroredStrings())->not->toHaveKey('');
});

// Same line-275 guard, isolating the `$pluralOriginal !== ''` half (an
// explicitly empty msgid_plural, rather than an absent one) from the
// `!== null` half the test above covers.
test('load does not mirror a plural translation when msgid_plural is explicitly empty', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "Beta"
        msgid_plural ""
        msgstr "BetaTranslated"
        msgstr[1] "BetaPluralForm"
        PO);

    $this->translator->load('en', $this->poFile);

    expect($this->translator->mirroredStrings())->not->toHaveKey('');
});

// Same line-275 guard, this time with a real msgid_plural but no msgstr[1]
// at all, so $pluralForm0 is null: unmutated, `is_string(null)` is false
// and the second `&&` short-circuits. BooleanAndToBooleanOr on that
// second `&&` would let `null !== ''` (true) carry the chain instead
// (is_string(pluralForm0) is otherwise a tautology given
// translatePlural()'s own `string ...$translations` variadic type, so
// only the null case can distinguish AND from OR here).
test('load does not mirror a plural original when no msgstr[1] was declared', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "Gamma"
        msgid_plural "GammaPlural"
        msgstr "GammaTranslated"
        PO);

    $this->translator->load('en', $this->poFile);

    expect($this->translator->mirroredStrings())->not->toHaveKey('GammaPlural');
});

// Same line-275 guard, isolating the trailing `$pluralForm0 !== ''` half
// (an explicitly empty msgstr[1]) from the null half the test above
// covers.
test('load does not mirror a plural original when msgstr[1] is explicitly empty', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "Delta"
        msgid_plural "DeltaPlural"
        msgstr "DeltaTranslated"
        msgstr[1] ""
        PO);

    $this->translator->load('en', $this->poFile);

    expect($this->translator->mirroredStrings())->not->toHaveKey('DeltaPlural');
});

// load()'s own docblock documents the whole caching mechanism: the cache
// key folds in the PO file's own mtime, so a second load() of the SAME
// (path, mtime) pair should reuse the CachePools::translations() pool
// entry instead of reparsing -- none of that (pool selection at line 129,
// the null-safe getItem() and cache-key concatenation at line 130, the
// isHit() check at line 132, the cached-mirror foreach at line 143, the
// early return at line 147, the item!==null save guard at line 161, and
// the actual pool->save() call at line 168) is exercised by any test
// above, since none of them ever inspect a SECOND, independent Translator
// instance's state after re-loading the exact same (path, mtime) pair.
// This test forces a genuine cache round trip through the real,
// filesystem-backed CachePools::translations() pool (this repo has no
// ext-apcu, so CacheFactory auto-detects the filesystem adapter): load()
// once (a real cache miss, since $this->poFile's own random suffix is
// unique to this test run), then overwrite the file with DIFFERENT
// content while forcing filemtime() back to the exact value the first
// call cached its key under (touch() after file_put_contents(), which
// would otherwise bump mtime to "now") -- a fresh Translator instance
// loading that same (path, mtime) pair a second time can only see the
// original, cached translation if the whole chain above actually ran. If
// any single link were skipped (pool forced null, isHit() bypassed, the
// cache-hit branch's own return removed so it falls through to a fresh
// parse, the mirror foreach dropped, or the save on the first load's
// miss skipped or never persisted), this test would observe the file's
// real, changed on-disk content instead.
test('load reuses a cached parse across independent Translator instances for the same path and mtime', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "CacheKey"
        msgstr "OriginalValue"
        PO);

    $this->translator->load('en', $this->poFile);

    $mtime = filemtime($this->poFile);
    if ($mtime === false) {
        throw new RuntimeException('expected filemtime() to succeed on a file this test just wrote');
    }

    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "CacheKey"
        msgstr "NewValue"
        PO);
    touch($this->poFile, $mtime);

    $second = new Translator(CurrentConfigTestFactory::get());
    $second->load('en', $this->poFile);

    expect($second->translate('CacheKey'))->toBe('OriginalValue');

    $mirror = $second->mirroredStrings();
    expect($mirror)->toHaveKey('CacheKey')
        ->and($mirror['CacheKey'])->toBe('OriginalValue');
});

// Same cache mechanism as the test above, isolating line 130's own
// ConcatRemoveRight mutation that drops $mtime from the key entirely
// (`md5($poFile . '_')`): that mutant would make EVERY load() of this
// same path share one cache entry forever, regardless of mtime -- exactly
// the staleness load()'s own docblock says folding in mtime prevents.
// Unlike the test above (which forces the SAME mtime to prove a cache hit
// happens at all), this one lets mtime change naturally between two
// load() calls on the same path and asserts the edit is not missed.
test("load's cache key busts on a real mtime change, not just staying pinned to the file path", function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "BustKey"
        msgstr "First"
        PO);

    $this->translator->load('en', $this->poFile);

    $firstMtime = filemtime($this->poFile);
    if ($firstMtime === false) {
        throw new RuntimeException('expected filemtime() to succeed on a file this test just wrote');
    }

    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "BustKey"
        msgstr "Second"
        PO);
    touch($this->poFile, $firstMtime + 1000);

    $second = new Translator(CurrentConfigTestFactory::get());
    $second->load('en', $this->poFile);

    expect($second->translate('BustKey'))->toBe('Second');
});

// Isolates line 130's own separator between $poFile and $mtime
// (ConcatRemoveRight dropping it, and ConcatSwitchSides moving it to a
// leading '_' with $poFile and $mtime still bare-concatenated against
// each other): without a real separator between the two, two DIFFERENT
// (path, mtime) pairs can hash to the SAME cache key purely because one
// file's trailing digit(s) plus its mtime spell out the same characters
// as the other file's own (shorter) trailing digit plus its (longer)
// mtime. $fileA/$fileB below are deliberately chosen so "$fileA" . "23"
// and "$fileB" . "3" are the exact same string, while "$fileA" . "_23"
// and "$fileB" . "_3" are not -- the real `$poFile . '_' . $mtime`
// formula never collides here; a bare-concatenation (or
// leading-underscore-only) formula does.
//
// (line 130's remaining ConcatSwitchSides variant -- `$mtime . ($poFile .
// '_')`, mtime FIRST -- is confirmed-equivalent instead: every real
// $poFile this class ever receives is an absolute filesystem path
// starting with '/', a character that can never appear inside $mtime's
// own pure-digit string, so the mtime/path boundary stays unambiguously
// self-delimiting without needing the separator at all, for any real
// (int $mtime, absolute-path $poFile) pair -- unlike the path-then-mtime
// order this test isolates, where a path's own trailing characters CAN
// legitimately be digits.)
test("load's cache key needs a real separator between the file path and mtime, not just their bare concatenation", function (): void {
    $prefix = sys_get_temp_dir() . '/piwigo-po-collide-' . bin2hex(random_bytes(6)) . '-';
    $fileA = $prefix . '1';
    $fileB = $prefix . '12';

    file_put_contents($fileA, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "CollideKey"
        msgstr "FromFileA"
        PO);
    touch($fileA, 23);

    file_put_contents($fileB, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "CollideKey"
        msgstr "FromFileB"
        PO);
    touch($fileB, 3);

    try {
        $this->translator->load('en', $fileA);

        $second = new Translator(CurrentConfigTestFactory::get());
        $second->load('en', $fileB);

        expect($second->translate('CollideKey'))->toBe('FromFileB');
    } finally {
        unlink($fileA);
        unlink($fileB);
    }
});
