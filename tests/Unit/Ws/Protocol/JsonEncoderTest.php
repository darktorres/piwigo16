<?php

declare(strict_types=1);

use Piwigo\Ws\NamedArray;
use Piwigo\Ws\NamedStruct;
use Piwigo\Ws\Protocol\JsonEncoder;
use Piwigo\Ws\WsErrorResponse;

/**
 * JsonEncoder -- the JSON sibling of SerialPhpEncoder/RestEncoder
 * (see SerialPhpEncoderTest.php for the shared flatten()/WsErrorResponse
 * fixture rationale, reused verbatim here). No dedicated Integration/
 * Browser spec of its own.
 *
 * WsError::INVALID_PARAM-style codes (>= 1000) are used for the WsErrorResponse
 * fixture, not an HTTP-range code (400-599), for the same reason as
 * SerialPhpEncoderTest.php: WsErrorResponse's constructor calls
 * PresentationAccessor::htmlService() for HTTP-range codes, which needs
 * a booted container this Unit test doesn't set up.
 */
test('encodeResponse json-encodes a WsErrorResponse as a fail/err/message triple', function (): void {
    $encoder = new JsonEncoder();
    $error = new WsErrorResponse(1003, 'Invalid param foo');

    $result = $encoder->encodeResponse($error);

    expect($result)
        ->toBe('{"stat":"fail","err":1003,"message":"Invalid param foo"}')
        ->and(json_decode((string) $result, true))
        ->toBe([
            'stat' => 'fail',
            'err' => 1003,
            'message' => 'Invalid param foo',
        ]);
});

test('encodeResponse json-encodes a plain array response as stat=ok/result', function (): void {
    $encoder = new JsonEncoder();

    $result = $encoder->encodeResponse([
        'id' => 7,
        'name' => 'Alps',
    ]);

    expect($result)
        ->toBe('{"stat":"ok","result":{"id":7,"name":"Alps"}}');
});

test('encodeResponse flattens a NamedStruct, merging its attributes_xml_ marker key into the result', function (): void {
    $encoder = new JsonEncoder();
    $response = new NamedStruct(
        [
            'id' => 7,
            'name' => 'Alps',
            'attributes_xml_' => [
                'visible' => 1,
            ],
        ],
        []
    );

    $result = $encoder->encodeResponse($response);

    expect($result)
        ->toBe('{"stat":"ok","result":{"id":7,"name":"Alps","visible":1}}')
        ->and(json_decode((string) $result, true))
        ->toBe([
            'stat' => 'ok',
            'result' => [
                'id' => 7,
                'name' => 'Alps',
                'visible' => 1,
            ],
        ]);
});

test('encodeResponse flattens a NamedArray to its plain list content, with no attributes merge', function (): void {
    $encoder = new JsonEncoder();
    $response = new NamedArray([10, 20, 30], 'item');

    $result = $encoder->encodeResponse($response);

    expect($result)
        ->toBe('{"stat":"ok","result":[10,20,30]}')
        ->and(json_decode((string) $result, true))
        ->toBe([
            'stat' => 'ok',
            'result' => [10, 20, 30],
        ]);
});

test('getContentType returns text/plain', function (): void {
    expect(new JsonEncoder()->getContentType())
        ->toBe('text/plain');
});
