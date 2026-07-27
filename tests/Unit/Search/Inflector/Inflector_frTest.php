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
    // '/(bleu|\x{FFFD}meu|landau|lieu|pneu|sarrau)$/' => '\1s' is tried
    // before the generic '/(bijou|...|eu|eau)$/' => '\1x' rule in the
    // reversed order, so 'pneu' becomes 'pneus', not 'pneux'.
    expect($this->inflector->get_variants('pneu'))->toBe(['pneus']);
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
    // '/(b|cor|<mangled-char>m|gemm|soupir|trav|vant|vitr)ail$/' pattern
    // => '\1aux' -- this specific rule is checked (in reversed order)
    // before the generic '/ail$/' => 'ails' rule, so 'travail' becomes
    // 'travaux', not 'travails'. No singularizer rule matches 'travail'
    // itself (it doesn't end in s/aux/ails/x), so only one variant comes back.
    expect($this->inflector->get_variants('travail'))->toBe(['travaux']);
});

test('the already-plural consonant+aux form round-trips back via the specific singularizer rule', function (): void {
    // Pluralizer: 'travaux' ends in 'x', the no-op '/(s|x|z)$/' rule matches first.
    // Singularizer: '/(b|cor|<mangled-char>m|gemm|soupir|trav|vant|vitr)aux$/'
    // => '\1ail' is checked before the generic '(journ|chev)aux$' rule and matches
    // on the literal 'trav' prefix, giving back 'travail'.
    expect($this->inflector->get_variants('travaux'))->toBe(['travaux', 'travail']);
});

test('a plain -ail word (no matching consonant prefix) pluralizes via the generic ail rule', function (): void {
    // 'detail' ends in 'ail' preceded by 'det', which is none of the
    // specific consonant-group alternatives ('b', 'cor', 'gemm',
    // 'soupir', 'trav', 'vant', 'vitr', ...), so the specific
    // consonant+ail rule does NOT match and the generic '/ail$/' =>
    // 'ails' rule applies instead: the literal 'ail' suffix is replaced
    // by the literal 'ails' string, giving 'det' + 'ails' = 'details'.
    expect($this->inflector->get_variants('detail'))->toBe(['details']);
});

test('the plain -ails plural round-trips back via the generic singularizer rule', function (): void {
    // Pluralizer: 'details' ends in 's', the no-op '/(s|x|z)$/' rule matches first.
    // Singularizer: '/ails$/' => 'ail' matches directly (checked before
    // the generic '/s$/' catch-all in the reversed order), since the
    // specific consonant+aux rule doesn't apply (no 'aux' suffix here).
    expect($this->inflector->get_variants('details'))->toBe(['details', 'detail']);
});
