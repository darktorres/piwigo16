<?php

declare(strict_types=1);

use Piwigo\Ws\Encoder\ResponseEncoder;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\NamedStruct;
use Piwigo\Ws\Protocol\RestEncoder;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Protocol\RestEncoder has no DB/HTTP dependency of its own --
 * it's a pure XML-serialization state machine over an arbitrary PHP value,
 * same shape as its XmlRpcEncoder/SerialPhpEncoder siblings in this
 * same directory -- so this follows their exact convention: encodeResponse()
 * is called directly with hand-built PHP values, not via a live WS request.
 *
 * tests/Contract/WsRestFormatTest.php already covers encode()'s 'boolean'
 * case and a struct null-value skip via real ws.php?format=rest requests,
 * and its own docblock explicitly flags three branches it does NOT chase
 * there because no real WS response exercises them: encode()'s 'NULL'
 * gettype() case, the generic get_object_vars() object fallback, and the
 * `default` resource/unknown-type trigger_error() branch. This file closes
 * exactly those three, plus encodeStruct()'s numeric-key skip, which had
 * no coverage anywhere.
 *
 * encodeStruct() no longer takes a skip_underscore flag -- real PHP
 * visibility (get_object_vars() called from outside a class already
 * excludes private/protected properties natively) governs what the
 * generic object fallback exposes, not a leading-underscore naming
 * convention. See 'encode() no longer hides leading-underscore keys...'
 * below for the test documenting that contract directly.
 *
 * encodeStruct() runs the *same* is_numeric()/null checks in two separate
 * foreach loops over the same $data (the first loop only peels off
 * xml_attributes/ATTRIBUTES_KEY entries via unset(); every other key --
 * including ones the checks below skip -- stays in $data for the second,
 * element-writing loop). So a key matching one of those skip conditions
 * hits the *same* check in both loops, never reaching either loop's write
 * path. Asserting on the final rendered XML (the only way to observe
 * encodeStruct()'s effect from the public API) is sufficient proof both
 * loops' checks fired: if either loop's check hadn't skipped it, the key
 * would show up as a written element.
 */
test('encode_struct skips an integer array key in both scan loops, writing no element for it', function (): void {
    // [3 => ..., 'label' => ...] is not array_is_list() (key 3 isn't the
    // list's expected leading 0), so encode()'s bare 'array' case routes
    // this through encodeStruct(), not encodeArray().
    $encoder = new RestEncoder();
    $response = [
        3 => 'numeric-key-value',
        'label' => 'kept',
    ];

    $result = $encoder->encodeResponse($response);

    $expected = <<<EOD
    <?xml version="1.0" encoding="utf-8" ?>
    <rsp stat="ok">
    <label>kept</label>
    </rsp>
    EOD;
    expect($result)
        ->toBe($expected)
        ->and($result)
        ->not->toContain('numeric-key-value');
});

test('encode_struct skips a null-valued key in both scan loops, omitting the element entirely', function (): void {
    $encoder = new RestEncoder();
    $response = [
        'title' => 'Kept',
        'subtitle' => null,
    ];

    $result = $encoder->encodeResponse($response);

    $expected = <<<EOD
    <?xml version="1.0" encoding="utf-8" ?>
    <rsp stat="ok">
    <title>Kept</title>
    </rsp>
    EOD;
    expect($result)
        ->toBe($expected)
        ->and($result)
        ->not->toContain('<subtitle');
});

test('encode() writes empty content for a NULL list element, reached only via encodeArray(), never encodeStruct()', function (): void {
    // A null *struct* value never reaches encode() at all -- encodeStruct()
    // skips it before ever recursing (proven by the test above). The 'NULL'
    // gettype() case is only reachable for an element of a real *list*, via
    // encodeArray()'s unconditional `$this->encode($item, $xml_attributes)`
    // call, which has no null check of its own.
    $encoder = new RestEncoder();
    $response = [null, 'x'];

    $result = $encoder->encodeResponse($response);

    $expected = <<<EOD
    <?xml version="1.0" encoding="utf-8" ?>
    <rsp stat="ok">
    <item></item><item>x</item>
    </rsp>
    EOD;
    expect($result)
        ->toBe($expected);
});

test('encode() trigger_error()s an E_USER_WARNING for a resource value and writes no content for it', function (): void {
    // Every gettype() outcome that could mean $data is an object is already
    // handled by the 'object' case above (see the class's own inline
    // comment on the `default` arm) -- only a genuine resource (or PHP's
    // rare "unknown type") reaches here. fopen() gives a real, live PHP
    // resource, not a mock/stub of the class under test.
    $encoder = new RestEncoder();
    $resource = fopen('php://memory', 'r');
    expect($resource)
        ->not->toBeFalse();
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

    expect($captured)
        ->toBe([[E_USER_WARNING, 'Invalid type resource']]);

    $expected = <<<EOD
    <?xml version="1.0" encoding="utf-8" ?>
    <rsp stat="ok">

    </rsp>
    EOD;
    expect($result)
        ->toBe($expected);
});

/**
 * Mutation-sweep closure notes (encodeResponse()'s WsErrorResponse branch,
 * encodeStruct()'s twin scan loops, and encode()'s array/object
 * dispatch). Each test below is built to fail under one specific
 * mutant, traced by hand against the source before being written --
 * see each test's own comment for which mutant(s) it targets and why.
 */
test('encodeResponse renders a WsErrorResponse as a stat="fail" response, never routing it through the normal struct/object encode path', function (): void {
    // Kills `if ($response instanceof WsErrorResponse)` -> InstanceOfToFalse:
    // a false-forced check would fall through to `$this->encode($response)`,
    // which (WsErrorResponse not being a NamedArray/NamedStruct) would hit
    // the generic get_object_vars() fallback -- and WsErrorResponse's properties
    // are all private, so get_object_vars() from outside the class sees
    // none of them, producing an empty stat="ok" response instead.
    //
    // WsError-style code (>= 1000), not an HTTP-range 400-599 code, so
    // WsErrorResponse's own constructor doesn't reach for a booted
    // PresentationAccessor container -- same convention as the sibling
    // XmlRpcEncoder/SerialPhpEncoder unit tests.
    $encoder = new RestEncoder();
    $error = new WsErrorResponse(1003, 'Bad param <x>');

    $result = $encoder->encodeResponse($error);

    $expected = <<<EOD
    <?xml version="1.0"?>
    <rsp stat="fail">
    \t<err code="1003" msg="Bad param &lt;x&gt;" />
    </rsp>
    EOD;
    expect($result)
        ->toBe($expected);
});

test('encode_struct pulls a later xml_attributes-designated key out even after an earlier numeric key in the same struct (first scan loop)', function (): void {
    // Exercises encodeStruct()'s FIRST scan loop (attribute-extraction),
    // not just the second (element-writing) loop the numeric-key test
    // near the top of this file already covers: $xml_attributes is only
    // ever non-empty via a NamedStruct's own xmlAttributes, so 'id'
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
    $encoder = new RestEncoder();
    $response = [
        'group' => new NamedStruct([
            5 => 'ignored-numeric',
            'id' => 7,
            'name' => 'bar',
        ], ['id']),
    ];

    $result = $encoder->encodeResponse($response);

    expect($result)
        ->toContain('<group id="7">')
        ->and($result)
        ->toContain('<name>bar</name>')
        ->and($result)
        ->not->toContain('<id>')
        ->and($result)
        ->not->toContain('ignored-numeric');
});

test('encode_struct casts an integer attribute key to string before writing it', function (): void {
    // Kills line 104 RemoveStringCast: writeAttribute()'s own $name
    // parameter is declared `string $name`, and this file's own
    // strict_types=1 means passing a genuine int array key without the
    // (string) cast throws a real TypeError rather than PHP silently
    // coercing it. An int array key inside the ATTRIBUTES_KEY value is a
    // genuinely valid PHP array shape ("attributes" aren't required to be
    // string-keyed) -- every existing ATTRIBUTES_KEY/xml_attributes test
    // in this file uses only string keys ('id'), so none of them force
    // this cast to actually do anything.
    $encoder = new RestEncoder();
    $response = [
        'group' => [
            ResponseEncoder::ATTRIBUTES_KEY => [
                0 => 'attr-value',
            ],
            'label' => 'Public',
        ],
    ];

    $result = $encoder->encodeResponse($response);

    expect($result)
        ->toContain('<group 0="attr-value">')
        ->and($result)
        ->toContain('<label>Public</label>');
});

test('encode() extracts an ATTRIBUTES_KEY property as an xml attribute source through the generic object fallback', function (): void {
    // The generic get_object_vars() object fallback (an arbitrary object
    // that is neither NamedArray nor NamedStruct) still runs
    // encodeStruct()'s full first scan loop, including the
    // ATTRIBUTES_KEY special case -- this proves that dispatch path
    // extracts real xml attributes from an arbitrary object's own
    // ATTRIBUTES_KEY property, same as the plain-array/NamedStruct
    // paths this file's other ATTRIBUTES_KEY tests already cover.
    $encoder = new RestEncoder();
    $response = new stdClass();
    $response->{ResponseEncoder::ATTRIBUTES_KEY} = [
        'id' => 9,
    ];
    $response->label = 'Public';
    $wrapper = [
        'group' => $response,
    ];

    $result = $encoder->encodeResponse($wrapper);

    expect($result)
        ->toContain('<group id="9">')
        ->and($result)
        ->toContain('<label>Public</label>')
        ->and($result)
        ->not->toContain('attributes_xml_');
});

test('encode_struct omits a null-valued xml_attributes-designated key entirely, never as an empty attribute (first scan loop)', function (): void {
    // Kills line 95 IfNegated and IdenticalToNotIdentical (both invert
    // `$value === null`, so the first loop stops `continue`-ing on the
    // null 'id' entry -- which the *original* code never lets reach the
    // isset($xml_attributes[...]) check at all -- and instead falls
    // through to writeAttribute('id', null), rendering a real but empty
    // `id=""` attribute instead of omitting 'id' altogether).
    $encoder = new RestEncoder();
    $response = [
        'group' => new NamedStruct([
            'id' => null,
            'name' => 'foo',
        ], ['id']),
    ];

    $result = $encoder->encodeResponse($response);

    expect($result)
        ->toContain('<group>')
        ->and($result)
        ->not->toContain('id=')
        ->and($result)
        ->toContain('<name>foo</name>');
});

test('encode_struct (first scan loop) only skips a null-valued entry itself, not every entry after it', function (): void {
    // Kills line 96 ContinueToBreak: the null-valued 'skip' entry is
    // deliberately placed *before* the ATTRIBUTES_KEY-eligible 'id' entry,
    // so a `break` instead of `continue` aborts the loop on 'skip' and
    // 'id' never gets pulled out as an attribute.
    $encoder = new RestEncoder();
    $response = [
        'group' => new NamedStruct([
            'skip' => null,
            'id' => 9,
            'name' => 'foo',
        ], ['id']),
    ];

    $result = $encoder->encodeResponse($response);

    expect($result)
        ->toContain('<group id="9">')
        ->and($result)
        ->toContain('<name>foo</name>')
        ->and($result)
        ->not->toContain('<skip');
});

test('encode_struct (second, element-writing scan loop) only skips a null-valued entry itself, not every entry after it', function (): void {
    // Kills line 121 ContinueToBreak, the second loop's own counterpart
    // to the test above: 'middle' is null and sits *before* 'last', so a
    // `break` instead of `continue` would abort the whole element-writing
    // loop and 'last' would never be written at all.
    $encoder = new RestEncoder();
    $response = [
        'first' => 'A',
        'middle' => null,
        'last' => 'B',
    ];

    $result = $encoder->encodeResponse($response);

    expect($result)
        ->toContain('<first>A</first>')
        ->and($result)
        ->toContain('<last>B</last>')
        ->and($result)
        ->not->toContain('<middle');
});

test('encode() routes a NamedArray through encodeArray()/its own content, not the generic object fallback', function (): void {
    // Kills line 162 InstanceOfToFalse: a false-forced check sends a
    // NamedArray to the final generic-object `else` branch instead --
    // get_object_vars() on it (from outside the class) sees its public
    // content/itemName/xmlAttributes properties, and -- now that
    // skip_underscore no longer exists to hide them -- would encode them
    // as a wrapping <content>/<itemName>/<xmlAttributes> struct instead
    // of the flat two <item> elements. An exact toBe() match is required
    // here, not toContain(): under the mutant, the wrong output
    // (`<content><item>a</item><item>b</item></content><itemName>item</itemName>...`)
    // still contains the literal substrings '<item>a</item>'/'<item>b</item>',
    // so a toContain() assertion would silently stop catching this mutant.
    $encoder = new RestEncoder();
    $response = new NamedArray(['a', 'b'], 'item');

    $result = $encoder->encodeResponse($response);

    $expected = <<<EOD
    <?xml version="1.0" encoding="utf-8" ?>
    <rsp stat="ok">
    <item>a</item><item>b</item>
    </rsp>
    EOD;
    expect($result)
        ->toBe($expected);
});

test('encode() routes a NamedStruct through encodeStruct()/its own content, not the generic object fallback', function (): void {
    // Kills line 164 InstanceOfToFalse, the NamedStruct counterpart to
    // the NamedArray test above: get_object_vars() would see only
    // content/xmlAttributes (both public), and -- with skip_underscore
    // gone -- the mutant would wrap the output in a <content> element
    // instead of writing <title>Hello</title> directly. Same toBe()
    // reasoning as the NamedArray test above: toContain() would not
    // distinguish the two.
    //
    // Explicit `[]` for $xmlAttributes (rather than the default null)
    // turns off NamedStruct's own auto-attribute-detection, so 'title'
    // is unambiguously a plain child element here, not an xml attribute
    // -- this response is encoded directly at the top level (no
    // enclosing parent element for an attribute to attach to).
    $encoder = new RestEncoder();
    $response = new NamedStruct([
        'title' => 'Hello',
    ], []);

    $result = $encoder->encodeResponse($response);

    $expected = <<<EOD
    <?xml version="1.0" encoding="utf-8" ?>
    <rsp stat="ok">
    <title>Hello</title>
    </rsp>
    EOD;
    expect($result)
        ->toBe($expected);
});

test('encode() no longer hides leading-underscore keys anywhere -- plain array, NamedStruct, or the generic object fallback', function (): void {
    // Confirms the new, correct contract now that skip_underscore is
    // gone: real PHP visibility (not a leading-underscore naming
    // convention) governs what's exposed. Covers all three routes
    // encode()/encodeStruct() can reach a leading-underscore key
    // through -- a plain associative array, a NamedStruct's own
    // content, and an arbitrary object via get_object_vars() -- since
    // all three now behave identically (no more special-casing).
    $encoder = new RestEncoder();

    $arrayResult = $encoder->encodeResponse([
        '_hidden' => 'should-appear',
        'visible' => 'yes',
    ]);
    expect($arrayResult)
        ->toContain('<_hidden>should-appear</_hidden>')
        ->and($arrayResult)
        ->toContain('<visible>yes</visible>');

    $structEncoder = new RestEncoder();
    $structResult = $structEncoder->encodeResponse(new NamedStruct([
        '_hidden' => 'should-appear',
        'label' => 'Public',
    ], []));
    expect($structResult)
        ->toContain('<_hidden>should-appear</_hidden>')
        ->and($structResult)
        ->toContain('<label>Public</label>');

    $objectEncoder = new RestEncoder();
    $response = new stdClass();
    $response->_formerlyHidden = 'now-visible';
    $response->label = 'Public';
    $objectResult = $objectEncoder->encodeResponse($response);
    expect($objectResult)
        ->toContain('<_formerlyHidden>now-visible</_formerlyHidden>')
        ->and($objectResult)
        ->toContain('<label>Public</label>');
});
