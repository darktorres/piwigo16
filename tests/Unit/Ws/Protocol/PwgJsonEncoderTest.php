<?php

declare(strict_types=1);

use Piwigo\Ws\Protocol\PwgJsonEncoder;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;

/**
 * PwgJsonEncoder -- the JSON sibling of PwgSerialPhpEncoder/PwgRestEncoder
 * (see PwgSerialPhpEncoderTest.php for the shared flatten()/PwgError
 * fixture rationale, reused verbatim here). No dedicated Integration/
 * Browser spec of its own.
 *
 * WsError::INVALID_PARAM-style codes (>= 1000) are used for the PwgError
 * fixture, not an HTTP-range code (400-599), for the same reason as
 * PwgSerialPhpEncoderTest.php: PwgError's constructor calls
 * PresentationAccessor::htmlService() for HTTP-range codes, which needs
 * a booted container this Unit test doesn't set up.
 */
test('encodeResponse json-encodes a PwgError as a fail/err/message triple', function (): void {
    $encoder = new PwgJsonEncoder();
    $error = new PwgError(1003, 'Invalid param foo');

    $result = $encoder->encodeResponse($error);

    expect($result)->toBe('{"stat":"fail","err":1003,"message":"Invalid param foo"}')
        ->and(json_decode((string) $result, true))->toBe(['stat' => 'fail', 'err' => 1003, 'message' => 'Invalid param foo']);
});

test('encodeResponse json-encodes a plain array response as stat=ok/result', function (): void {
    $encoder = new PwgJsonEncoder();

    $result = $encoder->encodeResponse(['id' => 7, 'name' => 'Alps']);

    expect($result)->toBe('{"stat":"ok","result":{"id":7,"name":"Alps"}}');
});

test('encodeResponse flattens a PwgNamedStruct, merging its attributes_xml_ marker key into the result', function (): void {
    $encoder = new PwgJsonEncoder();
    $response = new PwgNamedStruct(
        ['id' => 7, 'name' => 'Alps', 'attributes_xml_' => ['visible' => 1]],
        []
    );

    $result = $encoder->encodeResponse($response);

    expect($result)->toBe('{"stat":"ok","result":{"id":7,"name":"Alps","visible":1}}')
        ->and(json_decode((string) $result, true))->toBe(['stat' => 'ok', 'result' => ['id' => 7, 'name' => 'Alps', 'visible' => 1]]);
});

test('encodeResponse flattens a PwgNamedArray to its plain list content, with no attributes merge', function (): void {
    $encoder = new PwgJsonEncoder();
    $response = new PwgNamedArray([10, 20, 30], 'item');

    $result = $encoder->encodeResponse($response);

    expect($result)->toBe('{"stat":"ok","result":[10,20,30]}')
        ->and(json_decode((string) $result, true))->toBe(['stat' => 'ok', 'result' => [10, 20, 30]]);
});

test('getContentType returns text/plain', function (): void {
    expect(new PwgJsonEncoder()->getContentType())->toBe('text/plain');
});
