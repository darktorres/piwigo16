<?php

declare(strict_types=1);

use Piwigo\Search\Inflector\Inflector_en;

/**
 * Piwigo\Search\Inflector\Inflector_en::get_variants() -- the quick-search
 * word-stemming engine (generates plural/singular/verb-form variants of a
 * search term so e.g. searching "cat" also matches "cats"). Had zero
 * dedicated coverage despite being pure, deterministic, side-effect-free
 * logic. Every expected value below was independently confirmed by
 * invoking the real class before writing the assertion (this engine's
 * multi-stage regex fallthrough is not something to hand-trace and trust
 * blindly).
 */
test('a regular singular word gets its "s"-suffixed plural as a variant', function (): void {
    $inflector = new Inflector_en();

    expect($inflector->get_variants('cat'))
        ->toBe(['cats']);
});

test('a regular plural word gets its singular as a variant', function (): void {
    $inflector = new Inflector_en();

    expect($inflector->get_variants('cats'))
        ->toBe(['cat']);
});

test('an irregular exception word maps directly to its counterpart', function (): void {
    $inflector = new Inflector_en();

    expect($inflector->get_variants('man'))
        ->toBe(['men']);
    expect($inflector->get_variants('men'))
        ->toBe(['man']);
});

test('an uncountable exception word (0 in the exceptions map) has no variants', function (): void {
    $inflector = new Inflector_en();

    expect($inflector->get_variants('fish'))
        ->toBe([]);
});

test('a consonant+y word pluralizes via the "ies" rule', function (): void {
    $inflector = new Inflector_en();

    expect($inflector->get_variants('city'))
        ->toBe(['cities']);
    expect($inflector->get_variants('cities'))
        ->toBe(['city']);
});

test('an x/ch/ss/sh-ending word pluralizes via the "es" rule', function (): void {
    $inflector = new Inflector_en();

    expect($inflector->get_variants('box'))
        ->toBe(['boxes']);
    expect($inflector->get_variants('boxes'))
        ->toBe(['box']);
});

test('a hive/quiz/bus/octopus-style word uses its dedicated pluralization rule', function (): void {
    $inflector = new Inflector_en();

    expect($inflector->get_variants('hive'))
        ->toBe(['hives']);
    expect($inflector->get_variants('quiz'))
        ->toBe(['quizzes']);
    expect($inflector->get_variants('bus'))
        ->toBe(['buses']);
    expect($inflector->get_variants('octopus'))
        ->toBe(['octopuses']);
});

test('a word over 4 chars ending in "er" also gets an -ing variant (er2ing branch)', function (): void {
    $inflector = new Inflector_en();

    // 'runner' (6 chars, > 4 and > 5) exercises both the pluralizer and
    // the er2ing branch: 'runners' (plural) then 'running' (er->ing).
    expect($inflector->get_variants('runner'))
        ->toBe(['runners', 'running']);
});

test('a word over 5 chars ending in "ing" also gets an -er variant plus that variant\'s own plural (ing2er branch)', function (): void {
    $inflector = new Inflector_en();

    // 'running' (7 chars): the generic pluralizer fallback ('runnings'),
    // then ing2er ('runner'), then the pluralizer re-applied to that new
    // 'runner' variant ('runners').
    expect($inflector->get_variants('running'))
        ->toBe(['runnings', 'runner', 'runners']);
});

test('a short word (<=4 chars) never reaches the er2ing branch', function (): void {
    $inflector = new Inflector_en();

    // 'car' (3 chars) only gets the plain pluralizer variant -- too short
    // for either the er2ing or ing2er branch to run at all.
    expect($inflector->get_variants('car'))
        ->toBe(['cars']);
});

/**
 * Exhaustive-table-verification pass: for
 * every array entry pest --mutate flagged as an untested RemoveArrayItem
 * (or an UnwrapArrayReverse/UnwrapStrtolower/EmptyStringToNotEmpty on
 * these tables), the test below uses a real word that specifically
 * requires THAT entry to be present -- not a word that an earlier rule
 * in the same array already catches first (run() returns on first
 * match). Every expected value was independently confirmed by invoking
 * the real class first, then each targeted entry was temporarily
 * blanked in the source and the relevant assertion below re-run to
 * confirm it actually fails -- not just reasoned about by hand (this
 * cascade is exactly what the class's own docblock warns against
 * hand-tracing).
 */
test('further irregular exception words map to their counterparts', function (): void {
    $inflector = new Inflector_en();

    expect($inflector->get_variants('virus'))
        ->toBe(['viruses']);
    expect($inflector->get_variants('person'))
        ->toBe(['people']);
    expect($inflector->get_variants('woman'))
        ->toBe(['women']);
    expect($inflector->get_variants('child'))
        ->toBe(['children']);
    expect($inflector->get_variants('mouse'))
        ->toBe(['mice']);
    expect($inflector->get_variants('ox'))
        ->toBe(['oxen']);
});

test('the zombie/serie/movie exceptions need a case- or direction-sensitive witness, not their plain forward direction', function (): void {
    // Like move/moves above, 'zombie' -> 'zombies' and 'movie' ->
    // 'movies' also happen to be exactly what the generic pluralizer
    // fallback produces on its own -- confirmed live, blanking either
    // entry still leaves those two forward directions unchanged. The
    // reverse direction is the real witness: without the exception,
    // 'zombies' falls through to the ([^aeiouy]|qu)y$ singularizer rule
    // and becomes 'zomby' (not 'zombie'), and 'movies' becomes 'movy'.
    $inflector = new Inflector_en();

    expect($inflector->get_variants('zombie'))
        ->toBe(['zombies']);
    expect($inflector->get_variants('zombies'))
        ->toBe(['zombie']);
    expect($inflector->get_variants('movie'))
        ->toBe(['movies']);
    expect($inflector->get_variants('movies'))
        ->toBe(['movie']);

    // 'serie' is worse: its reverse key 'series' is unconditionally
    // overwritten to the uncountable marker 0 by the explode() loop a
    // few lines below the exceptions table regardless of whether this
    // entry exists, so neither direction in lowercase distinguishes it
    // (both 'serie'->['series'] and its would-be regex fallback happen
    // to agree, exactly like 'move'/'zombie'/'movie' above). What *does*
    // distinguish it: the exception table always returns its stored
    // value verbatim ('series', lowercase) regardless of the input's
    // case, while the regex fallback preserves the input's original
    // case. Confirmed live: blanking the entry turns
    // get_variants('SERIE') from ['series'] into ['SERIEs'].
    expect($inflector->get_variants('SERIE'))
        ->toBe(['series']);
});

test('the move/moves exception is only provably exercised in the moves->move direction', function (): void {
    // 'move' -> 'moves' also happens to be exactly what the generic
    // pluralizer fallback (append 's') produces on its own, so that
    // direction alone can't prove the exception entry is what produced
    // it. The reverse direction is not so lucky: without the exception,
    // 'moves' falls through to the singularizer's generic
    // ([^f])ves$ rule and becomes 'mofe', not 'move' -- confirmed live
    // by blanking the entry.
    $inflector = new Inflector_en();

    expect($inflector->get_variants('move'))
        ->toBe(['moves']);
    expect($inflector->get_variants('moves'))
        ->toBe(['move']);
});

test('further pluralizer rules produce their specific irregular plurals', function (): void {
    $inflector = new Inflector_en();

    // '/^(ax|test)is$/' => '\1es' (line 70)
    expect($inflector->get_variants('axis'))
        ->toBe(['axes']);
    // '/(alias|status)$/' => '\1es' (line 71)
    expect($inflector->get_variants('status'))
        ->toBe(['statuses']);
    // '/(buffal|tomat)o$/' => '\1oes' (line 73)
    expect($inflector->get_variants('tomato'))
        ->toBe(['tomatoes']);
    // '/([ti])um$/' => '\1a' (line 74)
    expect($inflector->get_variants('datum'))
        ->toBe(['data']);
    // '/([ti])a$/' => '\1a' (line 75) is a self-mapping rule (its
    // replacement just reconstructs the same 2-char suffix it matched);
    // its witness is 'media' below, in the singularizer test, which
    // proves it correctly suppresses the word (no wrongly-appended
    // 'medias') while the singularizer's own line 88 rule fires.
    // '/sis$/' => 'ses' (line 76)
    expect($inflector->get_variants('thesis'))
        ->toBe(['theses']);
    // '/(?:([^f])fe|([lr])f)$/' => '\1\2ves' (line 77)
    expect($inflector->get_variants('knife'))
        ->toBe(['knives']);
    // '/(matr|vert|ind)(?:ix|ex)$/' => '\1ices' (line 81) -- without it,
    // 'index' would fall through to the generic (x|ch|ss|sh)$ rule and
    // wrongly become 'indexes' instead of 'indices'.
    expect($inflector->get_variants('index'))
        ->toBe(['indices']);
});

test('further singularizer rules produce their specific irregular singulars', function (): void {
    $inflector = new Inflector_en();

    // '/(ss)$/' => '\1' (line 87) is another self-mapping rule; its
    // witness is that it suppresses the word so the generic '/s$/' =>
    // '' rule never wrongly strips a single 's' off ('glasses' would
    // otherwise also produce 'glas').
    expect($inflector->get_variants('glass'))
        ->toBe(['glasses']);
    // '/([ti])a$/' => '\1um' (line 88) -- shares its witness with the
    // pluralizer's line 75 self-mapping rule above: 'media' producing
    // exactly ['medium'] proves both that this rule fires and that the
    // pluralizer rule adds nothing.
    expect($inflector->get_variants('media'))
        ->toBe(['medium']);
    // the big analy|ba|diagno|parenthe|progno|synop|the alternation
    // (line 89) -- 'analyses' also exercises this rule's own (a)naly
    // branch, but does NOT distinguish line 90 (see the documented
    // equivalence below the last test in this file).
    expect($inflector->get_variants('diagnoses'))
        ->toBe(['diagnosis']);
    expect($inflector->get_variants('analyses'))
        ->toBe(['analysis']);
    // '/([^f])ves$/' => '\1fe' (line 91)
    expect($inflector->get_variants('caves'))
        ->toBe(['cafe']);
    // '/(hive)s$/' => '\1' (line 92) -- without it, 'hives' would fall
    // through to the ([^f])ves$ rule and wrongly become 'hife'.
    expect($inflector->get_variants('hives'))
        ->toBe(['hive']);
    // '/(tive)s$/' => '\1' (line 93) -- without it, 'natives' would fall
    // through to ([^f])ves$ and wrongly become 'natife'.
    expect($inflector->get_variants('natives'))
        ->toBe(['native']);
    // '/([lr])ves$/' => '\1f' (line 94)
    expect($inflector->get_variants('wolves'))
        ->toBe(['wolf']);
    // '/(o)es$/' => '\1' (line 98)
    expect($inflector->get_variants('heroes'))
        ->toBe(['hero']);
    // '/(shoe)s$/' => '\1' (line 99) -- without it, 'shoes' would fall
    // through to (o)es$ and wrongly become 'sho'.
    expect($inflector->get_variants('shoes'))
        ->toBe(['shoe']);
    // '/(cris|test)(is|es)$/' => '\1is' (line 100)
    expect($inflector->get_variants('crises'))
        ->toBe(['crisis']);
    // '/^(a)x[ie]s$/' => '\1xis' (line 101)
    expect($inflector->get_variants('axes'))
        ->toBe(['axis']);
    // '/(alias|status)(es)?$/' => '\1' (line 102)
    expect($inflector->get_variants('aliases'))
        ->toBe(['alias']);
    // '/(vert|ind)ices$/' => '\1ex' (line 103)
    expect($inflector->get_variants('indices'))
        ->toBe(['index']);
    // '/(matr)ices$/' => '\1ix' (line 104)
    expect($inflector->get_variants('matrices'))
        ->toBe(['matrix']);
    // '/(quiz)zes$/' => '\1' (line 105)
    expect($inflector->get_variants('quizzes'))
        ->toBe(['quiz']);
    // '/(database)s$/' => '\1' (line 106)
    expect($inflector->get_variants('databases'))
        ->toBe(['database']);
});

test('er2ing correctly excludes be/draw/liv-ending words from the generic -ing rule', function (): void {
    // 'liver' (5 chars, > 4) would become 'living' under the generic
    // /ers?$/ rule (line 110) if the (be|draw|liv)ers?$ exclusion entry
    // (line 111) weren't present -- which also requires er2ing's
    // array_reverse (line 109) to actually put that exclusion ahead of
    // the generic rule.
    $inflector = new Inflector_en();

    expect($inflector->get_variants('liver'))
        ->toBe(['livers']);
});

test('ing2er correctly excludes snow/rain, th/hous/dur/spr/wedd, and liv/draw-ending words from the generic -er rule', function (): void {
    $inflector = new Inflector_en();

    // '/(snow|rain)ing$/' => '\1' (line 116) drops the "ing" entirely,
    // keeping just the "snow"/"rain" prefix, instead of becoming
    // 'snower'.
    expect($inflector->get_variants('snowing'))
        ->toBe(['snowings', 'snow', 'snows']);
    // '/(th|hous|dur|spr|wedd)ing$/' => '\0' (line 117) leaves the word
    // untouched instead of becoming 'wedder' -- also requires ing2er's
    // array_reverse (line 114) to check this exclusion before the
    // generic rule.
    expect($inflector->get_variants('wedding'))
        ->toBe(['weddings']);
    // '/(liv|draw)ing$/' => '\0' (line 118) leaves the word untouched
    // instead of becoming 'drawer'.
    expect($inflector->get_variants('drawing'))
        ->toBe(['drawings']);
});

test('the exceptions lookup is case-insensitive via strtolower', function (): void {
    // Without strtolower() (line 131), 'MAN' would miss the lowercase
    // 'man' key entirely and fall through to the regex pipeline instead
    // of the exception table.
    $inflector = new Inflector_en();

    expect($inflector->get_variants('MAN'))
        ->toBe(['men']);
    expect($inflector->get_variants('Fish'))
        ->toBe([]);
});

test('an exception value that IS an empty string is never pushed as a variant', function (): void {
    // No real entry in the fixed exceptions table is ever an empty
    // string, so the `$rc !== ''` guard (line 135) is normally
    // unreachable from public input. Reflection exercises it directly:
    // without that check, an empty-string exception value would be
    // wrongly pushed into $res instead of being skipped.
    $inflector = new Inflector_en();
    $property = new ReflectionProperty($inflector, 'exceptions');
    $exceptions = $property->getValue($inflector);
    if (! is_array($exceptions)) {
        throw new RuntimeException('Expected exceptions to be an array');
    }

    $exceptions['blankword'] = '';
    $property->setValue($inflector, $exceptions);

    expect($inflector->get_variants('blankword'))
        ->toBe([]);
});

test('strlen(word) > 4 is the exact threshold for entering the er2ing branch', function (): void {
    $inflector = new Inflector_en();

    // 'over' is exactly 4 chars: too short to enter er2ing (line 143),
    // so no 'oving' variant is produced even though it ends in 'er'.
    expect($inflector->get_variants('over'))
        ->toBe(['overs']);
    // 'diner' is exactly 5 chars: long enough to enter er2ing.
    expect($inflector->get_variants('diner'))
        ->toBe(['diners', 'dining']);
});

test('strlen(word) > 5 is the exact threshold for entering the ing2er branch', function (): void {
    $inflector = new Inflector_en();

    // 'swing' is exactly 5 chars: too short to enter ing2er (line 146),
    // so no 'swer' variant is produced even though it ends in 'ing'.
    expect($inflector->get_variants('swing'))
        ->toBe(['swings']);
    // 'boring' is exactly 6 chars: long enough to enter ing2er.
    expect($inflector->get_variants('boring'))
        ->toBe(['borings', 'borer', 'borers']);
});

test('run() applies its regex rules case-insensitively', function (): void {
    // Without the 'i' modifier concatenated onto every rule's pattern
    // (line 163), uppercase 'BOX' would miss the (x|ch|ss|sh)$ rule
    // (which needs a lowercase literal to match) and fall through to
    // the generic '/$/' => 's' fallback instead, producing 'BOXs'
    // rather than 'BOXes'.
    $inflector = new Inflector_en();

    expect($inflector->get_variants('BOX'))
        ->toBe(['BOXes']);
});

/**
 * Confirmed-equivalent mutations (verified via live source mutation --
 * blanking/altering the line, running the suite, restoring -- not
 * assumed from reasoning alone):
 *
 * - Line 64's DecrementInteger (`-1` instead of `0`) and IncrementInteger
 *   (`1` instead of `0`) on `$this->exceptions[$v] = 0;` (the
 *   uncountable-word marker): the only place this value is ever read is
 *   `is_string($rc)` in get_variants() -- the exact integer is never
 *   compared, echoed, or otherwise used, so any int value makes
 *   is_string() false and produces the identical `[]` result. Confirmed
 *   live: the uncountable-word test ('fish' => []) still passes with
 *   the marker set to -1, and separately with it set to 1.
 * - Line 90's RemoveArrayItem (`'/(^analy)(sis|ses)$/' => '\1sis'`):
 *   structurally shadowed by line 89's own `(a)naly` alternative branch,
 *   checked immediately after it. Line 89's branch has no `^` anchor, so
 *   for any word matching line 90 (which requires the string to START
 *   with literal "analy"), line 89's `(a)naly` branch also matches that
 *   same substring and produces the byte-identical replacement (`\1` is
 *   "analy" either way). There is no reachable input where line 90 fires
 *   with a result line 89 wouldn't independently reproduce. Confirmed
 *   live across 'analyses' and 'Analyses' (case-insensitive 'i' flag on
 *   both rules) with line 90 commented out -- output unchanged.
 * - Line 78's RemoveArrayItem (`'/(hive)$/' => '\1s'`): this rule's
 *   replacement always reconstructs "whatever text it matched" + "s",
 *   which is byte-for-byte identical to the universal catch-all
 *   fallback rule ('/$/' => 's') that always runs last. No other
 *   pluralizer rule can intercept a "*hive"-ending word first (checked:
 *   none of fe/lf/rf, sis, [ti]a, [ti]um, buffalo/tomato, bus,
 *   alias/status, ax/testis, x/ch/ss/sh, or y can ever match a word
 *   ending "...hive"). Confirmed live: 'hive' still pluralizes to
 *   ['hives'] with the rule commented out.
 * - Line 163's DecrementInteger (`-2` instead of `-1` as preg_replace()'s
 *   $limit): PHP's preg_replace() treats every negative limit
 *   identically to -1 (unlimited) -- confirmed directly against the PHP
 *   engine for both a single-match end-anchored pattern (as every rule
 *   in this class is) and a multi-match pattern.
 * - Line 164's RemoveBooleanCast (`if ($count)` instead of
 *   `if ((bool) $count)`): $count is always the plain int replacement
 *   count preg_replace() writes back by reference (never null, per the
 *   adjacent assert()); PHP's `if()` applies the exact same truthiness
 *   coercion to an int that an explicit `(bool)` cast would, so the two
 *   are language-level identical for every possible $count value.
 */
