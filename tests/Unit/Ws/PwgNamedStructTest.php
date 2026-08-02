<?php

declare(strict_types=1);

use Piwigo\Ws\PwgNamedStruct;

/**
 * PwgNamedStruct::__construct() branches on `isset($xmlAttributes)`:
 * when the caller supplies an explicit list, it is flipped into a
 * name => position map (same shape as PwgNamedArray's own flip); when
 * omitted (null), the constructor instead auto-detects xml-attribute
 * candidates by walking $_content itself (scalar/null values, keys not
 * in ['', 0, '0']), writing $key => 1 for each. Only the
 * PwgSerialPhpEncoderTest.php fixture exercised this class before, and
 * only via the explicit (empty-array) branch -- the null/auto-detect
 * branch had no coverage anywhere.
 */
test('explicit xmlAttributes flips the given list into a name => position map', function (): void {
    $struct = new PwgNamedStruct(['id' => 7, 'name' => 'Alps'], ['id']);

    expect($struct->_xmlAttributes)->toBe(['id' => 0])
        ->and($struct->_content)->toBe(['id' => 7, 'name' => 'Alps']);
});

test('null xmlAttributes auto-detects scalar content keys instead of flipping a list', function (): void {
    // Same $_content as the explicit-array test above, but with
    // xmlAttributes omitted -- proves the two branches produce
    // meaningfully different $_xmlAttributes for identical input,
    // and that the auto-detect branch really does walk $_content
    // (not simply an empty/flipped-nothing result).
    $struct = new PwgNamedStruct(['id' => 7, 'name' => 'Alps']);

    expect($struct->_xmlAttributes)->toBe(['id' => 1, 'name' => 1]);
});

test('null xmlAttributes auto-detect skips non-scalar values and keys forced into xmlElements', function (): void {
    $struct = new PwgNamedStruct(
        ['id' => 7, 'name' => 'Alps', 'comments' => ['first', 'second']],
        null,
        ['name'],
    );

    // 'comments' is an array (not scalar/null) so it's never a candidate;
    // 'name' is scalar but explicitly forced to stay an xml element via
    // $xmlElements, so only 'id' ends up in _xmlAttributes.
    expect($struct->_xmlAttributes)->toBe(['id' => 1]);
});

test('null xmlAttributes auto-detect excludes the "" and 0 keys even when scalar', function (): void {
    // Note: a literal '0' array key is normalized to the int key 0 by PHP
    // itself before this constructor ever sees it, so only these two
    // distinct key shapes are reachable through a real foreach here.
    $struct = new PwgNamedStruct(['id' => 7, '' => 'blank', 0 => 'zero']);

    expect($struct->_xmlAttributes)->toBe(['id' => 1]);
});
