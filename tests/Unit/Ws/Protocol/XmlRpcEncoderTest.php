<?php

declare(strict_types=1);

use Piwigo\Ws\NamedArray;
use Piwigo\Ws\Protocol\XmlRpcEncoder;
use Piwigo\Ws\PwgError;

/**
 * XmlRpcEncoder::xmlrpcEncode() switches on gettype() per value
 * (boolean/integer/double/string/array-or-object), and for arrays
 * decides "list" vs "struct" via the same
 * `range(0, count($data)-1) === array_keys($data)` idiom the parent
 * class's own isStruct() uses. Every expected XML fragment below was
 * built by tracing that switch by hand, piece by piece, then
 * cross-checked for the PHP-builtin pieces only (htmlspecialchars(),
 * (string) float casting) via standalone `php -r` calls -- never by
 * running the encoder itself and copying its output.
 *
 * As with the SerialPhpEncoder tests, PwgError fixtures use a
 * WsError-style code (>= 1000), not an HTTP-range 400-599 code, to
 * avoid PwgError's constructor reaching for a booted container.
 *
 * Deliberately NOT tested: the `(string)` cast on the 'double' case's
 * `return '<double>' . (string) $data . '</double>';`. $data is only
 * ever a genuine `float` there (gettype($data) === 'double' guards the
 * branch), and for a float, PHP's implicit string-context conversion
 * (plain concatenation) is byte-for-byte identical to an explicit
 * `(string)` cast in every case checked -- 1.0, 4.5, huge/tiny
 * magnitudes, NAN, INF, -0.0, and across `precision` ini values of 4,
 * -1, 14 (default), and 17 -- confirmed live via
 * `sed -i "80s/(string) \$data/\$data/" src/.../XmlRpcEncoder.php`
 * followed by rerunning this suite (all green) and reverting. Removing
 * that cast is a true equivalent mutant here: no input can distinguish
 * the two, so no test is added to "kill" it.
 */
test('encodeResponse renders a PwgError as a methodResponse/fault', function (): void {
    $encoder = new XmlRpcEncoder();
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

    expect($result)
        ->toBe($expected);
});

test('encodeResponse renders a struct response mixing every scalar type plus a nested list', function (): void {
    $encoder = new XmlRpcEncoder();
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

    expect($result)
        ->toBe($expected);
});

test('encodeResponse renders an empty array response as an empty struct, not an empty list', function (): void {
    // range(0, count([])-1) is range(0,-1) = [0,-1], which never equals
    // array_keys([]) = [] -- so an empty array takes the *struct*
    // branch (matching the parent class's own isStruct() having the
    // same quirk), producing "<struct>\n</struct>" with no members.
    $encoder = new XmlRpcEncoder();

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

    expect($result)
        ->toBe($expected);
});

test('encodeResponse renders a raw stdClass object via the object branch of the type switch', function (): void {
    // xmlrpcEncode()'s switch has a dedicated 'object' case (gettype()
    // === 'object') that get_object_vars()'s the value before applying
    // the same list-vs-struct check as arrays -- untested by the
    // array-only fixture above. Also: flatten() only unwraps
    // NamedArray/NamedStruct and only recurses into is_array()
    // values, so a raw stdClass response passes through flattenResponse()
    // completely untouched and reaches xmlrpcEncode() as-is.
    $encoder = new XmlRpcEncoder();
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

    expect($result)
        ->toBe($expected);
});

test('encodeResponse renders a null field as an empty value tag via the switch fallthrough', function (): void {
    // xmlrpcEncode()'s switch on gettype() has no case for 'NULL' (only
    // boolean/integer/double/string/object/array) -- the bare `return '';`
    // below the switch is what actually runs for a null value, producing
    // an empty <value></value> (not valid per the XML-RPC spec's own
    // <nil/> extension, but this is the real, pre-existing encoding
    // behavior for a genuinely nullable WS response field, e.g.
    // pwg.session.getStatus's own 'connected_with' before any login).
    $encoder = new XmlRpcEncoder();
    $response = [
        'connected_with' => null,
    ];

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

    expect($result)
        ->toBe($expected);
});

test('encodeResponse actually flattens a NamedArray wrapper before encoding', function (): void {
    // If parent::flattenResponse($response) weren't called, $response
    // would still be a NamedArray *object* by the time it reaches
    // xmlrpcEncode(). That method has no special case for NamedArray --
    // it would fall into the generic 'object' branch and get_object_vars()
    // would leak content/itemName/xmlAttributes as struct members
    // instead of encoding the wrapped list directly. Calling flatten
    // first unwraps $response to plain [1, 2, 3] before xmlrpcEncode()
    // ever sees it, so the list branch runs and none of those wrapper
    // property names appear anywhere in the output.
    $encoder = new XmlRpcEncoder();
    $response = new NamedArray([1, 2, 3], 'item');

    $result = $encoder->encodeResponse($response);

    $list = "<array><data>\n"
        . "  <value><int>1</int></value>\n"
        . "  <value><int>2</int></value>\n"
        . "  <value><int>3</int></value>\n"
        . '</data></array>';

    $expected = <<<EOD
    <methodResponse>
      <params>
        <param>
          <value>
            {$list}
          </value>
        </param>
      </params>
    </methodResponse>
    EOD;

    expect($result)
        ->toBe($expected);
});

test('encodeResponse casts a non-string (integer) struct key to string before escaping', function (): void {
    // Struct member names come straight from a plain array's own keys,
    // which can be integers (PHP auto-casts a numeric-string key like
    // '3' to int(3)) -- htmlspecialchars() requires a string argument
    // under this file's strict_types, so without the (string) cast on
    // $name this would fatal with a TypeError for any non-string key.
    // A lone int key already forces the struct branch on its own:
    // array_keys([3 => 'three']) is [3], which never equals
    // range(0, 0) = [0].
    $encoder = new XmlRpcEncoder();
    $response = [
        3 => 'three',
    ];

    $result = $encoder->encodeResponse($response);

    $struct = "<struct>\n"
        . "  <member><name>3</name><value><string>three</string></value></member>\n"
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

    expect($result)
        ->toBe($expected);
});

test('encodeResponse escapes HTML-special characters in a struct member name', function (): void {
    $encoder = new XmlRpcEncoder();
    $response = [
        '<tag>' => 'value',
        'a&b' => 'other',
    ];

    $result = $encoder->encodeResponse($response);

    $struct = "<struct>\n"
        . "  <member><name>&lt;tag&gt;</name><value><string>value</string></value></member>\n"
        . "  <member><name>a&amp;b</name><value><string>other</string></value></member>\n"
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

    expect($result)
        ->toBe($expected);
});

test('getContentType returns text/xml', function (): void {
    expect(new XmlRpcEncoder()->getContentType())
        ->toBe('text/xml');
});
