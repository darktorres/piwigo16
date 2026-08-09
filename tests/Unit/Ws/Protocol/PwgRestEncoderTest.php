<?php

declare(strict_types=1);

use Piwigo\Ws\Encoder\PwgResponseEncoder;
use Piwigo\Ws\Protocol\PwgRestEncoder;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;

/**
 * Piwigo\Ws\Protocol\PwgRestEncoder has no DB/HTTP dependency of its own --
 * it's a pure XML-serialization state machine over an arbitrary PHP value,
 * same shape as its PwgXmlRpcEncoder/PwgSerialPhpEncoder siblings in this
 * same directory -- so this follows their exact convention: encodeResponse()
 * is called directly with hand-built PHP values, not via a live WS request.
 *
 * tests/Contract/WsRestFormatTest.php already covers encode()'s 'boolean'
 * case and a struct null-value skip via real ws.php?format=rest requests,
 * and its own docblock explicitly flags three branches it does NOT chase
 * there because no real WS response exercises them: encode()'s 'NULL'
 * gettype() case, the generic get_object_vars() object fallback, and the
 * `default` resource/unknown-type trigger_error() branch. This file closes
 * exactly those three, plus encode_struct()'s numeric-key skip and its
 * skip_underscore behaviour (only reachable via that same generic object
 * fallback), which had no coverage anywhere.
 *
 * encode_struct() runs the *same* is_numeric()/skip_underscore/null checks
 * in two separate foreach loops over the same $data (the first loop only
 * peels off xml_attributes/ATTRIBUTES_KEY entries via unset(); every other
 * key -- including ones the checks below skip -- stays in $data for the
 * second, element-writing loop). So a key matching one of those three skip
 * conditions hits the *same* check in both loops, never reaching either
 * loop's write path. Asserting on the final rendered XML (the only way to
 * observe encode_struct()'s effect from the public API) is sufficient proof
 * both loops' checks fired: if either loop's check hadn't skipped it, the
 * key would show up as a written element.
 */
test('encode_struct skips an integer array key in both scan loops, writing no element for it', function (): void {
    // [3 => ..., 'label' => ...] is not array_is_list() (key 3 isn't the
    // list's expected leading 0), so encode()'s bare 'array' case routes
    // this through encode_struct(), not encode_array().
    $encoder = new PwgRestEncoder();
    $response = [3 => 'numeric-key-value', 'label' => 'kept'];

    $result = $encoder->encodeResponse($response);

    $expected = <<<EOD
    <?xml version="1.0" encoding="utf-8" ?>
    <rsp stat="ok">
    <label>kept</label>
    </rsp>
    EOD;
    expect($result)->toBe($expected)
        ->and($result)->not->toContain('numeric-key-value');
});

test('encode_struct skips a null-valued key in both scan loops, omitting the element entirely', function (): void {
    $encoder = new PwgRestEncoder();
    $response = ['title' => 'Kept', 'subtitle' => null];

    $result = $encoder->encodeResponse($response);

    $expected = <<<EOD
    <?xml version="1.0" encoding="utf-8" ?>
    <rsp stat="ok">
    <title>Kept</title>
    </rsp>
    EOD;
    expect($result)->toBe($expected)
        ->and($result)->not->toContain('<subtitle');
});

test('encode_struct with skip_underscore=true skips a leading-underscore key, in both scan loops', function (): void {
    // skip_underscore is only ever true via encode()'s generic
    // get_object_vars() object fallback -- the PwgNamedStruct/PwgNamedArray
    // branches both pass false. A plain object that is neither of those
    // wrapper types is exactly what reaches that fallback, so this fixture
    // also proves encode()'s own `else { encode_struct(get_object_vars($data), true); }`
    // line and the `break` right after it.
    $encoder = new PwgRestEncoder();
    $response = new stdClass();
    $response->_secret = 'TopSecret';
    $response->label = 'Public';

    $result = $encoder->encodeResponse($response);

    $expected = <<<EOD
    <?xml version="1.0" encoding="utf-8" ?>
    <rsp stat="ok">
    <label>Public</label>
    </rsp>
    EOD;
    expect($result)->toBe($expected)
        ->and($result)->not->toContain('TopSecret')
        ->and($result)->not->toContain('_secret');
});

test('encode() writes empty content for a NULL list element, reached only via encode_array(), never encode_struct()', function (): void {
    // A null *struct* value never reaches encode() at all -- encode_struct()
    // skips it before ever recursing (proven by the test above). The 'NULL'
    // gettype() case is only reachable for an element of a real *list*, via
    // encode_array()'s unconditional `$this->encode($item, $xml_attributes)`
    // call, which has no null check of its own.
    $encoder = new PwgRestEncoder();
    $response = [null, 'x'];

    $result = $encoder->encodeResponse($response);

    $expected = <<<EOD
    <?xml version="1.0" encoding="utf-8" ?>
    <rsp stat="ok">
    <item></item><item>x</item>
    </rsp>
    EOD;
    expect($result)->toBe($expected);
});

test('encode() trigger_error()s an E_USER_WARNING for a resource value and writes no content for it', function (): void {
    // Every gettype() outcome that could mean $data is an object is already
    // handled by the 'object' case above (see the class's own inline
    // comment on the `default` arm) -- only a genuine resource (or PHP's
    // rare "unknown type") reaches here. fopen() gives a real, live PHP
    // resource, not a mock/stub of the class under test.
    $encoder = new PwgRestEncoder();
    $resource = fopen('php://memory', 'r');
    expect($resource)->not->toBeFalse();
    // assert() is a genuine no-op at runtime in this environment
    // (zend.assertions=-1) -- the expect() above is the real runtime guard;
    // this exists only so PHPStan narrows $resource from resource|false to
    // resource for the encodeResponse()/fclose() calls below.
    assert($resource !== false);

    /** @var array<int, array{0: int, 1: string}> $captured */
    $captured = [];
    set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
        $captured[] = [$errno, $errstr];

        return true;
    });
    try {
        $result = $encoder->encodeResponse($resource);
    } finally {
        restore_error_handler();
        fclose($resource);
    }

    expect($captured)->toBe([[E_USER_WARNING, 'Invalid type resource']]);

    $expected = <<<EOD
    <?xml version="1.0" encoding="utf-8" ?>
    <rsp stat="ok">

    </rsp>
    EOD;
    expect($result)->toBe($expected);
});

/**
 * Mutation-sweep closure notes (encodeResponse()'s PwgError branch,
 * encode_struct()'s twin scan loops, and encode()'s array/object
 * dispatch). Each test below is built to fail under one specific
 * mutant, traced by hand against the source before being written --
 * see each test's own comment for which mutant(s) it targets and why.
 */
test('encodeResponse renders a PwgError as a stat="fail" response, never routing it through the normal struct/object encode path', function (): void {
    // Kills `if ($response instanceof PwgError)` -> InstanceOfToFalse:
    // a false-forced check would fall through to `$this->encode($response)`,
    // which (PwgError not being a PwgNamedArray/PwgNamedStruct) would hit
    // the generic get_object_vars() fallback -- and PwgError's properties
    // are all private, so get_object_vars() from outside the class sees
    // none of them, producing an empty stat="ok" response instead.
    //
    // WsError-style code (>= 1000), not an HTTP-range 400-599 code, so
    // PwgError's own constructor doesn't reach for a booted
    // PresentationAccessor container -- same convention as the sibling
    // PwgXmlRpcEncoder/PwgSerialPhpEncoder unit tests.
    $encoder = new PwgRestEncoder();
    $error = new PwgError(1003, 'Bad param <x>');

    $result = $encoder->encodeResponse($error);

    $expected = <<<EOD
    <?xml version="1.0"?>
    <rsp stat="fail">
    \t<err code="1003" msg="Bad param &lt;x&gt;" />
    </rsp>
    EOD;
    expect($result)->toBe($expected);
});

test('encode_struct pulls a later xml_attributes-designated key out even after an earlier numeric key in the same struct (first scan loop)', function (): void {
    // Exercises encode_struct()'s FIRST scan loop (attribute-extraction),
    // not just the second (element-writing) loop the numeric-key test
    // near the top of this file already covers: $xml_attributes is only
    // ever non-empty via a PwgNamedStruct's own _xmlAttributes, so 'id'
    // must be pulled out here as a real XML attribute on <group>, never
    // as a child element -- and placing a numeric key *before* it proves
    // the loop keeps going past that entry instead of stopping on it.
    //
    // Kills line 88 ForeachEmptyIterable (loop body never runs -> 'id'
    // never pulled out), line 89 IfNegated (is_numeric() check inverted
    // -> the non-numeric 'id'/'name' entries wrongly `continue` instead
    // of the numeric one), and line 90 ContinueToBreak (the numeric
    // entry's `continue` becomes `break`, aborting the whole loop before
    // it ever reaches 'id').
    $encoder = new PwgRestEncoder();
    $response = ['group' => new PwgNamedStruct([5 => 'ignored-numeric', 'id' => 7, 'name' => 'bar'], ['id'])];

    $result = $encoder->encodeResponse($response);

    expect($result)->toContain('<group id="7">')
        ->and($result)->toContain('<name>bar</name>')
        ->and($result)->not->toContain('<id>')
        ->and($result)->not->toContain('ignored-numeric');
});

test('encode_struct casts an integer attribute key to string before writing it', function (): void {
    // Kills line 104 RemoveStringCast: write_attribute()'s own $name
    // parameter is declared `string $name`, and this file's own
    // strict_types=1 means passing a genuine int array key without the
    // (string) cast throws a real TypeError rather than PHP silently
    // coercing it. An int array key inside the ATTRIBUTES_KEY value is a
    // genuinely valid PHP array shape ("attributes" aren't required to be
    // string-keyed) -- every existing ATTRIBUTES_KEY/xml_attributes test
    // in this file uses only string keys ('id'), so none of them force
    // this cast to actually do anything.
    $encoder = new PwgRestEncoder();
    $response = ['group' => [PwgResponseEncoder::ATTRIBUTES_KEY => [0 => 'attr-value'], 'label' => 'Public']];

    $result = $encoder->encodeResponse($response);

    expect($result)->toContain('<group 0="attr-value">')
        ->and($result)->toContain('<label>Public</label>');
});

test('encode_struct (skip_underscore path) still special-cases a non-underscore ATTRIBUTES_KEY property as an xml attribute source', function (): void {
    // skip_underscore=true is only ever reached via encode()'s generic
    // get_object_vars() object fallback, which never threads a non-empty
    // $xml_attributes through -- so the *only* way to observe this first
    // loop's `$skip_underscore and $name[0] === '_'` condition actually
    // pulling something out of $data (rather than merely not-writing it,
    // already covered by the skip_underscore test near the top of this
    // file) is via the ATTRIBUTES_KEY special case, whose name
    // ('attributes_xml_') does NOT start with an underscore but DOES end
    // with one.
    //
    // Kills line 92 IfNegated and IdenticalToNotIdentical (both invert
    // the condition, so the true skip_underscore=true + non-underscore-
    // first-char combination wrongly skips this entry instead of
    // processing it), LogicalAndToLogicalOr (`or` makes the whole
    // condition unconditionally true whenever skip_underscore is true,
    // regardless of $name), and DecrementInteger (`$name[-1]` is the
    // *last* character of 'attributes_xml_', which -- unlike the first --
    // genuinely is '_', so checking it wrongly skips this entry too).
    $encoder = new PwgRestEncoder();
    $response = new stdClass();
    $response->{PwgResponseEncoder::ATTRIBUTES_KEY} = ['id' => 9];
    $response->label = 'Public';
    $wrapper = ['group' => $response];

    $result = $encoder->encodeResponse($wrapper);

    expect($result)->toContain('<group id="9">')
        ->and($result)->toContain('<label>Public</label>')
        ->and($result)->not->toContain('attributes_xml_');
});

test('encode_struct (skip_underscore path) checks the key\'s first character, not a shifted index', function (): void {
    // Kills line 92 IncrementInteger (`$name[0]` -> `$name[1]`). A
    // single-character property name only has a valid string offset at
    // 0 -- indexing 1 is out of range, and PHP raises a genuine
    // "Uninitialized string offset" E_WARNING for it (confirmed live via
    // `php -r '$n="_"; $n[1];'` under a matching error handler) that the
    // real, unmutated `$name[0]` never triggers for any key.
    //
    // This mutant happens to be output-invisible here (the second scan
    // loop still filters the entry out correctly either way, since only
    // this first-loop line is mutated), so the captured-warnings
    // assertion is the only signal that can kill it -- same
    // capture-and-assert technique this file's own resource/E_USER_WARNING
    // test above already uses.
    $encoder = new PwgRestEncoder();
    $response = new stdClass();
    $response->{'_'} = 'should-be-skipped-silently';
    $response->label = 'Public';

    /** @var array<int, array{0: int, 1: string}> $captured */
    $captured = [];
    set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
        $captured[] = [$errno, $errstr];

        return true;
    });
    try {
        $result = $encoder->encodeResponse($response);
    } finally {
        restore_error_handler();
    }

    expect($captured)->toBe([])
        ->and($result)->toContain('<label>Public</label>')
        ->and($result)->not->toContain('should-be-skipped-silently');
});

test('encode_struct (skip_underscore path, first scan loop) only skips the underscore-prefixed entry itself, not every entry after it', function (): void {
    // Kills line 93 ContinueToBreak: the underscore-prefixed '_secret'
    // entry is deliberately placed *before* the ATTRIBUTES_KEY entry, so
    // a `break` instead of `continue` aborts the whole loop on '_secret'
    // and 'id' never gets pulled out as an attribute, ending up as a
    // plain child element instead.
    $encoder = new PwgRestEncoder();
    $response = new stdClass();
    $response->_secret = 'hide-me';
    $response->{PwgResponseEncoder::ATTRIBUTES_KEY} = ['id' => 9];
    $response->label = 'Public';
    $wrapper = ['group' => $response];

    $result = $encoder->encodeResponse($wrapper);

    expect($result)->toContain('<group id="9">')
        ->and($result)->toContain('<label>Public</label>')
        ->and($result)->not->toContain('hide-me')
        ->and($result)->not->toContain('_secret');
});

test('encode_struct omits a null-valued xml_attributes-designated key entirely, never as an empty attribute (first scan loop)', function (): void {
    // Kills line 95 IfNegated and IdenticalToNotIdentical (both invert
    // `$value === null`, so the first loop stops `continue`-ing on the
    // null 'id' entry -- which the *original* code never lets reach the
    // isset($xml_attributes[...]) check at all -- and instead falls
    // through to write_attribute('id', null), rendering a real but empty
    // `id=""` attribute instead of omitting 'id' altogether).
    $encoder = new PwgRestEncoder();
    $response = ['group' => new PwgNamedStruct(['id' => null, 'name' => 'foo'], ['id'])];

    $result = $encoder->encodeResponse($response);

    expect($result)->toContain('<group>')
        ->and($result)->not->toContain('id=')
        ->and($result)->toContain('<name>foo</name>');
});

test('encode_struct (first scan loop) only skips a null-valued entry itself, not every entry after it', function (): void {
    // Kills line 96 ContinueToBreak: the null-valued 'skip' entry is
    // deliberately placed *before* the ATTRIBUTES_KEY-eligible 'id' entry,
    // so a `break` instead of `continue` aborts the loop on 'skip' and
    // 'id' never gets pulled out as an attribute.
    $encoder = new PwgRestEncoder();
    $response = ['group' => new PwgNamedStruct(['skip' => null, 'id' => 9, 'name' => 'foo'], ['id'])];

    $result = $encoder->encodeResponse($response);

    expect($result)->toContain('<group id="9">')
        ->and($result)->toContain('<name>foo</name>')
        ->and($result)->not->toContain('<skip');
});

test('encode_struct (second, element-writing scan loop) only skips a null-valued entry itself, not every entry after it', function (): void {
    // Kills line 121 ContinueToBreak, the second loop's own counterpart
    // to the test above: 'middle' is null and sits *before* 'last', so a
    // `break` instead of `continue` would abort the whole element-writing
    // loop and 'last' would never be written at all.
    $encoder = new PwgRestEncoder();
    $response = ['first' => 'A', 'middle' => null, 'last' => 'B'];

    $result = $encoder->encodeResponse($response);

    expect($result)->toContain('<first>A</first>')
        ->and($result)->toContain('<last>B</last>')
        ->and($result)->not->toContain('<middle');
});

test('encode() routes a plain PHP array through encode_struct() with skip_underscore=false, unlike the generic object fallback', function (): void {
    // Kills line 158 FalseToTrue: encode()'s bare 'array' case is the
    // only encode_struct() call site that hardcodes `false` for
    // skip_underscore. Forcing it `true` would make a plain associative
    // array behave like the get_object_vars() object fallback and
    // silently drop any underscore-prefixed key -- which a plain PHP
    // array response must NOT do.
    $encoder = new PwgRestEncoder();
    $response = ['_hidden' => 'should-appear', 'visible' => 'yes'];

    $result = $encoder->encodeResponse($response);

    expect($result)->toContain('<_hidden>should-appear</_hidden>')
        ->and($result)->toContain('<visible>yes</visible>');
});

test('encode() routes a PwgNamedArray through encode_array()/its own _content, not the generic object fallback', function (): void {
    // Kills line 162 InstanceOfToFalse: a false-forced check sends a
    // PwgNamedArray to the final generic-object `else` branch instead --
    // get_object_vars() on it (from outside the class) sees its public
    // _content/_itemName/_xmlAttributes properties, but skip_underscore=true
    // then drops all three (every one starts with an underscore),
    // producing no output at all instead of the two <item> elements.
    $encoder = new PwgRestEncoder();
    $response = new PwgNamedArray(['a', 'b'], 'item');

    $result = $encoder->encodeResponse($response);

    expect($result)->toContain('<item>a</item>')
        ->and($result)->toContain('<item>b</item>');
});

test('encode() routes a PwgNamedStruct through encode_struct()/its own _content, not the generic object fallback', function (): void {
    // Kills line 164 InstanceOfToFalse, the PwgNamedStruct counterpart to
    // the PwgNamedArray test above: get_object_vars() would see only
    // _content/_xmlAttributes (both underscore-prefixed, both public),
    // and skip_underscore=true in the generic fallback would drop both,
    // producing no output at all instead of the <title> element.
    //
    // Explicit `[]` for $xmlAttributes (rather than the default null)
    // turns off PwgNamedStruct's own auto-attribute-detection, so 'title'
    // is unambiguously a plain child element here, not an xml attribute
    // -- this response is encoded directly at the top level (no
    // enclosing parent element for an attribute to attach to).
    $encoder = new PwgRestEncoder();
    $response = new PwgNamedStruct(['title' => 'Hello'], []);

    $result = $encoder->encodeResponse($response);

    expect($result)->toContain('<title>Hello</title>');
});

test('encode() encodes a PwgNamedStruct without skipping its own underscore-prefixed keys', function (): void {
    // Kills line 167 FalseToTrue: the PwgNamedStruct branch's own
    // encode_struct() call hardcodes skip_underscore=false (only the
    // generic get_object_vars() object fallback a few lines below passes
    // true) -- a leading-underscore _content key must still be written as
    // a normal element for a PwgNamedStruct, unlike the object-fallback
    // path this file's own 'skips a leading-underscore key' test near the
    // top covers. The sibling 'routes a PwgNamedStruct...' test right
    // above has no underscore-prefixed _content key, so it can't
    // distinguish this mutant on its own.
    $encoder = new PwgRestEncoder();
    $response = new PwgNamedStruct(['_hidden' => 'should-appear', 'label' => 'Public'], []);

    $result = $encoder->encodeResponse($response);

    expect($result)->toContain('<_hidden>should-appear</_hidden>')
        ->and($result)->toContain('<label>Public</label>');
});
