<?php

declare(strict_types=1);

use Piwigo\Ws\Protocol\PwgXmlRpcEncoder;
use Piwigo\Ws\PwgError;

/**
 * PwgXmlRpcEncoder::xmlrpcEncode() switches on gettype() per value
 * (boolean/integer/double/string/array-or-object), and for arrays
 * decides "list" vs "struct" via the same
 * `range(0, count($data)-1) === array_keys($data)` idiom the parent
 * class's own is_struct() uses. Every expected XML fragment below was
 * built by tracing that switch by hand, piece by piece, then
 * cross-checked for the PHP-builtin pieces only (htmlspecialchars(),
 * (string) float casting) via standalone `php -r` calls -- never by
 * running the encoder itself and copying its output.
 *
 * As with the PwgSerialPhpEncoder tests, PwgError fixtures use a
 * WsError-style code (>= 1000), not an HTTP-range 400-599 code, to
 * avoid PwgError's constructor reaching for a booted container.
 */
test('encodeResponse renders a PwgError as a methodResponse/fault', function (): void {
    $encoder = new PwgXmlRpcEncoder();
    $error = new PwgError(1003, 'Bad param <x>');

    $result = $encoder->encodeResponse($error);

    $expected = <<<EOD
    <methodResponse>
      <fault>
        <value>
          <struct>
            <member>
              <name>faultCode</name>
              <value><int>1003</int></value>
            </member>
            <member>
              <name>faultString</name>
              <value><string>Bad param &lt;x&gt;</string></value>
            </member>
          </struct>
        </value>
      </fault>
    </methodResponse>
    EOD;

    expect($result)->toBe($expected);
});

test('encodeResponse renders a struct response mixing every scalar type plus a nested list', function (): void {
    $encoder = new PwgXmlRpcEncoder();
    $response = [
        'id' => 42,
        'name' => 'Piwigo & Friends <3',
        'rate' => 4.5,
        'visible' => true,
        'tags' => ['nature', 'city'],
    ];

    $result = $encoder->encodeResponse($response);

    $tagsArray = "<array><data>\n"
        . "  <value><string>nature</string></value>\n"
        . "  <value><string>city</string></value>\n"
        . '</data></array>';

    $struct = "<struct>\n"
        . "  <member><name>id</name><value><int>42</int></value></member>\n"
        . "  <member><name>name</name><value><string>Piwigo &amp; Friends &lt;3</string></value></member>\n"
        . "  <member><name>rate</name><value><double>4.5</double></value></member>\n"
        . "  <member><name>visible</name><value><boolean>1</boolean></value></member>\n"
        . "  <member><name>tags</name><value>{$tagsArray}</value></member>\n"
        . '</struct>';

    $expected = <<<EOD
    <methodResponse>
      <params>
        <param>
          <value>
            {$struct}
          </value>
        </param>
      </params>
    </methodResponse>
    EOD;

    expect($result)->toBe($expected);
});

test('encodeResponse renders an empty array response as an empty struct, not an empty list', function (): void {
    // range(0, count([])-1) is range(0,-1) = [0,-1], which never equals
    // array_keys([]) = [] -- so an empty array takes the *struct*
    // branch (matching the parent class's own is_struct() having the
    // same quirk), producing "<struct>\n</struct>" with no members.
    $encoder = new PwgXmlRpcEncoder();

    $result = $encoder->encodeResponse([]);

    $expected = <<<EOD
    <methodResponse>
      <params>
        <param>
          <value>
            <struct>
    </struct>
          </value>
        </param>
      </params>
    </methodResponse>
    EOD;

    expect($result)->toBe($expected);
});

test('encodeResponse renders a raw stdClass object via the object branch of the type switch', function (): void {
    // xmlrpcEncode()'s switch has a dedicated 'object' case (gettype()
    // === 'object') that get_object_vars()'s the value before applying
    // the same list-vs-struct check as arrays -- untested by the
    // array-only fixture above. Also: flatten() only unwraps
    // PwgNamedArray/PwgNamedStruct and only recurses into is_array()
    // values, so a raw stdClass response passes through flattenResponse()
    // completely untouched and reaches xmlrpcEncode() as-is.
    $encoder = new PwgXmlRpcEncoder();
    $response = new stdClass();
    $response->id = 9;
    $response->title = 'Peaks';

    $result = $encoder->encodeResponse($response);

    $struct = "<struct>\n"
        . "  <member><name>id</name><value><int>9</int></value></member>\n"
        . "  <member><name>title</name><value><string>Peaks</string></value></member>\n"
        . '</struct>';

    $expected = <<<EOD
    <methodResponse>
      <params>
        <param>
          <value>
            {$struct}
          </value>
        </param>
      </params>
    </methodResponse>
    EOD;

    expect($result)->toBe($expected);
});

test('encodeResponse renders a null field as an empty value tag via the switch fallthrough', function (): void {
    // xmlrpcEncode()'s switch on gettype() has no case for 'NULL' (only
    // boolean/integer/double/string/object/array) -- the bare `return '';`
    // below the switch is what actually runs for a null value, producing
    // an empty <value></value> (not valid per the XML-RPC spec's own
    // <nil/> extension, but this is the real, pre-existing encoding
    // behavior for a genuinely nullable WS response field, e.g.
    // pwg.session.getStatus's own 'connected_with' before any login).
    $encoder = new PwgXmlRpcEncoder();
    $response = ['connected_with' => null];

    $result = $encoder->encodeResponse($response);

    $struct = "<struct>\n"
        . "  <member><name>connected_with</name><value></value></member>\n"
        . '</struct>';

    $expected = <<<EOD
    <methodResponse>
      <params>
        <param>
          <value>
            {$struct}
          </value>
        </param>
      </params>
    </methodResponse>
    EOD;

    expect($result)->toBe($expected);
});

test('getContentType returns text/xml', function (): void {
    expect(new PwgXmlRpcEncoder()->getContentType())->toBe('text/xml');
});
