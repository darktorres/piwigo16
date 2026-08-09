<?php

declare(strict_types=1);

use Piwigo\Search\Inflector\Inflector_fr;

/**
 * get_variants() first checks a small hardcoded exception dictionary
 * (and its reverse), then otherwise runs two independent regex-rule
 * lists (pluralizers, singularizers) built via array_reverse() -- so
 * each list is actually tried in the REVERSE of its declared order,
 * meaning the more specific rules declared later run first. Every case
 * below was traced by hand against that reversed order and the real
 * regex alternatives (see the class's own $pluralizers/$singularizers
 * literals) before picking the expected value.
 */
beforeEach(function (): void {
    $this->inflector = new Inflector_fr();
});

test('a word in the exception dictionary returns only its mapped form', function (): void {
    expect($this->inflector->get_variants('monsieur'))->toBe(['messieurs']);
});

test('the exception dictionary is checked in both directions and is case-insensitive', function (): void {
    expect($this->inflector->get_variants('MESSIEURS'))->toBe(['monsieur']);
});

test('a second exception dictionary entry (madame/mesdames) maps directly, both directions', function (): void {
    // Distinct from the monsieur/messieurs pair covered above -- exercises
    // the 'madame' => 'mesdames' entry specifically (and its
    // constructor-built reverse), not just the first entry in the table.
    expect($this->inflector->get_variants('madame'))->toBe(['mesdames']);
    expect($this->inflector->get_variants('mesdames'))->toBe(['madame']);
});

test('a third exception dictionary entry (mademoiselle/mesdemoiselles) maps directly, both directions', function (): void {
    // Exercises the 'mademoiselle' => 'mesdemoiselles' entry specifically.
    expect($this->inflector->get_variants('mademoiselle'))->toBe(['mesdemoiselles']);
    expect($this->inflector->get_variants('mesdemoiselles'))->toBe(['mademoiselle']);
});

test('an exception dictionary entry mapped to the empty string yields no variant', function (): void {
    // None of this class's own hardcoded exceptions ('monsieur',
    // 'madame', 'mademoiselle' and their reverses) map to '', so the
    // `if ($rc !== '')` guard on the exception-lookup branch is otherwise
    // unreachable through the real table. It's exercised here by
    // injecting a test-only invariant entry via reflection (same
    // technique as ErrorCollectorTest's private-state seeding) -- this
    // directly proves the guard suppresses an empty-string match instead
    // of appending it to the result.
    $inflector = $this->inflector;

    $prop = new ReflectionProperty(Inflector_fr::class, 'exceptions');
    $exceptions = $prop->getValue($inflector);
    if (! is_array($exceptions)) {
        throw new RuntimeException('Expected exceptions to be an array');
    }

    $exceptions['invariant'] = '';
    $prop->setValue($inflector, $exceptions);

    expect($inflector->get_variants('invariant'))->toBe([]);
});

/**
 * The `(bool)` cast on `$count` (lines 83/95) and the `-1` replacement
 * limit passed to `preg_replace()` (lines 82/94) are both confirmed-
 * equivalent mutants, proved two ways:
 *  - Live verification: mutating `-1` to `-2` and removing `(bool)` in
 *    all four spots simultaneously, then re-running this entire file,
 *    still passes 13/13 -- no input distinguishes the mutated behavior.
 *  - By PHP semantics: `if ($count)` and `if ((bool) $count)` are
 *    identical for every value of $count, since `if` always applies the
 *    same boolean coercion as an explicit `(bool)` cast; and
 *    `preg_replace()`'s limit parameter treats *any* negative value
 *    (not just -1) as "unlimited" -- confirmed directly via
 *    `preg_replace('/a/', 'b', 'aaaa', -2, $c)` still replacing all 4
 *    occurrences, same as `-1` and `-100`.
 * No test can kill these; each is included below for the record rather
 * than pretended away.
 */
test('a regular word only gets the default appended-s plural, no singularizer match', function (): void {
    // 'chat' doesn't end in s/x/z/al/ail/aux/eu/eau/etc, so none of the
    // singularizer rules match it at all -- the result has exactly one
    // element, from the pluralizer's final catch-all '/$/' => 's' rule.
    expect($this->inflector->get_variants('chat'))->toBe(['chats']);
});

test('an irregular -al word pluralizes to -aux via the more specific rule', function (): void {
    // '/al$/' => 'aux' is checked before the generic catch-all in the
    // reversed pluralizer order. The singularizer list has no match for
    // 'cheval' itself (it doesn't end in s/aux/ails/x), so only one
    // variant comes back.
    expect($this->inflector->get_variants('cheval'))->toBe(['chevaux']);
});

test('the already-plural -aux form round-trips back to -al via the (journ|chev)aux rule', function (): void {
    // Pluralizer: 'chevaux' ends in 'x', so the '/(s|x|z)$/' => '\1' rule
    // matches first and is a no-op (replaces the 'x' with itself).
    // Singularizer: '/(journ|chev)aux$/' => '\1al' is checked before the
    // generic '/s$/' rule and matches on the literal 'chev' prefix.
    expect($this->inflector->get_variants('chevaux'))->toBe(['chevaux', 'cheval']);
});

test('a word ending in s is left unchanged by the pluralizer but stripped by the singularizer', function (): void {
    // Pluralizer: '/(s|x|z)$/' => '\1' matches and is a no-op.
    // Singularizer: only the generic catch-all '/s$/' => '' matches.
    expect($this->inflector->get_variants('bras'))->toBe(['bras', 'bra']);
});

test('a specific -eu exception pluralizes with s, overriding the generic -eu/-eau => x rule', function (): void {
    // '/(bleu|émeu|landau|lieu|pneu|sarrau)$/' => '\1s' is tried
    // before the generic '/(bijou|...|eu|eau)$/' => '\1x' rule in the
    // reversed order, so 'pneu' becomes 'pneus', not 'pneux'.
    expect($this->inflector->get_variants('pneu'))->toBe(['pneus']);
});

test('émeu and the ém-prefixed consonant group pluralize correctly, not via the generic fallback', function (): void {
    // Regression test for a real, confirmed data-corruption bug (found
    // during the mutation sweep): the 'é' in 'émeu' and 'ém'
    // had been replaced by a literal U+FFFD REPLACEMENT CHARACTER in the
    // regex alternations above (confirmed via hexdump -- genuine byte
    // corruption, not a display artifact), silently making the 'émeu'/
    // 'ém'-prefixed branches unmatchable by any real French word. Fixed
    // by restoring 'é', matching 16.x-rewrite's own (uncorrupted)
    // InflectorFr.php byte-for-byte.
    expect($this->inflector->get_variants('émeu'))->toBe(['émeus']);
    expect($this->inflector->get_variants('émail'))->toBe(['émaux']);
    expect($this->inflector->get_variants('émaux'))->toBe(['émaux', 'émail']);
});

test('a -ou word pluralizes with x via the bijou-family rule', function (): void {
    expect($this->inflector->get_variants('chou'))->toBe(['choux']);
});

test('the already-plural -oux form round-trips back via the bijou-family singularizer rule', function (): void {
    // Pluralizer: ends in 'x', the no-op '/(s|x|z)$/' rule matches first.
    // Singularizer: '/(bijou|...|eu|eau)x$/' => '\1' matches 'chou' + 'x'.
    expect($this->inflector->get_variants('choux'))->toBe(['choux', 'chou']);
});

test('a consonant+ail word pluralizes to the consonant+aux form via the specific rule', function (): void {
    // 'travail' ends in 'ail' preceded by 'trav', one of the literal
    // consonant-group alternatives in the class's own
    // '/(b|cor|ém|gemm|soupir|trav|vant|vitr)ail$/' pattern
    // => '\1aux' -- this specific rule is checked (in reversed order)
    // before the generic '/ail$/' => 'ails' rule, so 'travail' becomes
    // 'travaux', not 'travails'. No singularizer rule matches 'travail'
    // itself (it doesn't end in s/aux/ails/x), so only one variant comes back.
    expect($this->inflector->get_variants('travail'))->toBe(['travaux']);
});

test('the already-plural consonant+aux form round-trips back via the specific singularizer rule', function (): void {
    // Pluralizer: 'travaux' ends in 'x', the no-op '/(s|x|z)$/' rule matches first.
    // Singularizer: '/(b|cor|ém|gemm|soupir|trav|vant|vitr)aux$/'
    // => '\1ail' is checked before the generic '(journ|chev)aux$' rule and matches
    // on the literal 'trav' prefix, giving back 'travail'.
    expect($this->inflector->get_variants('travaux'))->toBe(['travaux', 'travail']);
});

/**
 * The '/ail$/' => 'ails' pluralizer entry is a confirmed-equivalent
 * mutant: replacing the matched 'ail' suffix with the literal string
 * 'ails' is byte-for-byte identical to just appending 's' to the whole
 * word (since 'ails' IS 'ail' + 's'), which is exactly what the
 * reversed order's final catch-all '/$/' => 's' rule does once this
 * entry is removed. Verified live: temporarily deleting this array
 * entry from the source and re-running this whole file still passes
 * 13/13, including the 'detail' case immediately below, and a wider
 * brute-force check across rail/email/gouvernail/portail/chandail/
 * attirail/eventail/serail/corail confirmed byte-identical output with
 * and without the rule in every case. No test can kill it.
 */
test('a plain -ail word (no matching consonant prefix) pluralizes via the generic ail rule', function (): void {
    // 'detail' ends in 'ail' preceded by 'det', which is none of the
    // specific consonant-group alternatives ('b', 'cor', 'gemm',
    // 'soupir', 'trav', 'vant', 'vitr', ...), so the specific
    // consonant+ail rule does NOT match and the generic '/ail$/' =>
    // 'ails' rule applies instead: the literal 'ail' suffix is replaced
    // by the literal 'ails' string, giving 'det' + 'ails' = 'details'.
    expect($this->inflector->get_variants('detail'))->toBe(['details']);
});

/**
 * The '/ails$/' => 'ail' singularizer entry is a confirmed-equivalent
 * mutant for the same reason as its pluralizer counterpart above:
 * replacing matched 'ails' with the literal 'ail' is byte-for-byte
 * identical to just stripping the trailing 's' ('ails' minus its last
 * char IS 'ail'), which is exactly what the generic '/s$/' => '' rule
 * does once this entry is removed. Verified live: temporarily deleting
 * this array entry and re-running this whole file still passes 13/13,
 * including the 'details' round-trip case immediately below. No test
 * can kill it.
 */
test('the plain -ails plural round-trips back via the generic singularizer rule', function (): void {
    // Pluralizer: 'details' ends in 's', the no-op '/(s|x|z)$/' rule matches first.
    // Singularizer: '/ails$/' => 'ail' matches directly (checked before
    // the generic '/s$/' catch-all in the reversed order), since the
    // specific consonant+aux rule doesn't apply (no 'aux' suffix here).
    expect($this->inflector->get_variants('details'))->toBe(['details', 'detail']);
});
