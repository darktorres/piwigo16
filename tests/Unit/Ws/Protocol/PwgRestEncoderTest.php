<?php

declare(strict_types=1);

use Piwigo\Ws\Protocol\PwgRestEncoder;

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
