<?php

declare(strict_types=1);

use Piwigo\Ws\Protocol\PwgSerialPhpEncoder;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;

/**
 * PwgSerialPhpEncoder wraps the response in ['stat'=>..., ...] and hands
 * it to PHP's own serialize() -- so the expected strings below were
 * computed via a standalone `php -r 'var_dump(serialize(...))'` on the
 * literal expected array (a pure PHP-builtin format check, not a call
 * into the class under test), then matched to what encodeResponse()
 * should produce.
 *
 * WsError::INVALID_PARAM-style codes (>= 1000) are used for the
 * PwgError fixture, not an HTTP-range code (400-599), because
 * PwgError's constructor calls PresentationAccessor::htmlService() for
 * HTTP-range codes, which needs a booted container this Unit test
 * doesn't set up.
 *
 * Ws\Encoder\PwgResponseEncoder::flatten()'s own re-assertion guard (`$is_struct
 * = self::is_struct($value); if (! is_array($value)) { return; }`,
 * directly below the earlier `if (! is_array($value)) { return; }`) is not
 * chased here or anywhere else: it exists purely because is_struct()
 * takes `$data` by reference with a `mixed` type, so PHPStan discards the
 * is_array() narrowing above across the call (see the source's own
 * docblock) -- but is_struct()'s real body never actually writes to
 * `$data` at all (confirmed by reading its full source: a plain read-only
 * `is_array($data)`/`array_keys($data)` check, `return`s the whole way
 * through). $value is therefore always still the same real array
 * flatten() already confirmed just above, and this second `return;` can
 * never execute through any real call. A pure static-analysis
 * narrowing-loss guard, provably unreachable in real usage -- not a gap
 * in test coverage.
 */
test('encodeResponse serializes a PwgError as a fail/err/message triple', function (): void {
    $encoder = new PwgSerialPhpEncoder();
    $error = new PwgError(1003, 'Invalid param foo');

    $result = $encoder->encodeResponse($error);

    expect($result)->toBe('a:3:{s:4:"stat";s:4:"fail";s:3:"err";i:1003;s:7:"message";s:17:"Invalid param foo";}')
        ->and(unserialize($result))->toBe(['stat' => 'fail', 'err' => 1003, 'message' => 'Invalid param foo']);
});

test('encodeResponse serializes a plain array response as stat=ok/result', function (): void {
    $encoder = new PwgSerialPhpEncoder();

    $result = $encoder->encodeResponse(['id' => 7, 'name' => 'Alps']);

    expect($result)->toBe('a:2:{s:4:"stat";s:2:"ok";s:6:"result";a:2:{s:2:"id";i:7;s:4:"name";s:4:"Alps";}}');
});

test('encodeResponse flattens a PwgNamedStruct, merging its attributes_xml_ marker key into the result', function (): void {
    // flattenResponse() (inherited from PwgResponseEncoder) unwraps the
    // PwgNamedStruct to its raw ->_content, then -- because is_struct()
    // is true for it -- merges the 'attributes_xml_' sub-array into the
    // parent and removes the marker key entirely.
    $encoder = new PwgSerialPhpEncoder();
    $response = new PwgNamedStruct(
        ['id' => 7, 'name' => 'Alps', 'attributes_xml_' => ['visible' => 1]],
        []
    );

    $result = $encoder->encodeResponse($response);

    expect($result)->toBe('a:2:{s:4:"stat";s:2:"ok";s:6:"result";a:3:{s:2:"id";i:7;s:4:"name";s:4:"Alps";s:7:"visible";i:1;}}')
        ->and(unserialize($result))->toBe(['stat' => 'ok', 'result' => ['id' => 7, 'name' => 'Alps', 'visible' => 1]]);
});

test('encodeResponse flattens a PwgNamedArray to its plain list content, with no attributes merge', function (): void {
    // flatten()'s other branch: PwgNamedArray unwraps to ->_content same
    // as PwgNamedStruct does, but a sequential-int-keyed list is NOT a
    // "struct" per is_struct(), so the attributes_xml_ merge block is
    // skipped entirely -- a genuinely different code path from the
    // PwgNamedStruct case above.
    $encoder = new PwgSerialPhpEncoder();
    $response = new PwgNamedArray([10, 20, 30], 'item');

    $result = $encoder->encodeResponse($response);

    expect($result)->toBe('a:2:{s:4:"stat";s:2:"ok";s:6:"result";a:3:{i:0;i:10;i:1;i:20;i:2;i:30;}}')
        ->and(unserialize($result))->toBe(['stat' => 'ok', 'result' => [10, 20, 30]]);
});

test('getContentType returns text/plain', function (): void {
    expect(new PwgSerialPhpEncoder()->getContentType())->toBe('text/plain');
});
