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
 * WsError::InvalidParam-style codes (>= 1000) are used for the
 * WsErrorResponse fixture, same as SerialPhpEncoderTest.php's own
 * fixture -- WsErrorResponse is a pure value object (P25 Stage 2 item 3
 * moved its former HTTP-status side effect into Server::sendResponse()
 * instead), so this is no longer load-bearing for constructing one
 * without a booted container, just kept consistent with the sibling
 * encoder tests.
 */
test('encodeResponse json-encodes a WsErrorResponse as a fail/err/message triple', function (): void {
    $encoder = new JsonEncoder();
    $error = new WsErrorResponse(1003, 'Invalid param foo');

    $result = $encoder->encodeResponse($error);

    expect($result)
        ->toBe('{"stat":"fail","err":1003,"message":"Invalid param foo"}')
        ->and(json_decode($result, true))
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
        ->and(json_decode($result, true))
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
        ->and(json_decode($result, true))
        ->toBe([
            'stat' => 'ok',
            'result' => [10, 20, 30],
        ]);
});

test('getContentType returns application/json', function (): void {
    expect(new JsonEncoder()->getContentType())
        ->toBe('application/json');
});
