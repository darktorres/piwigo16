<?php

declare(strict_types=1);

use Piwigo\Ws\PwgNamedArray;

/**
 * PwgNamedArray is a plain constructor-promoted value wrapper -- the only
 * real logic is `$this->xmlAttributes = array_flip($xmlAttributes);`,
 * which turns the given list of attribute names into a name => position
 * lookup map for the xml encoders (see Ws\Encoder\PwgXmlWriter's own
 * usage). A non-empty, order-sensitive fixture is required to distinguish
 * a real flip from an UnwrapArrayFlip mutant (array_flip() removed,
 * leaving the original list assigned as-is).
 */
test('constructor flips xmlAttributes into a name => position map, not the original list', function (): void {
    $array = new PwgNamedArray([10, 20, 30], 'item', ['width', 'height']);

    expect($array->xmlAttributes)
        ->toBe([
            'width' => 0,
            'height' => 1,
        ])
        ->and($array->content)
        ->toBe([10, 20, 30])
        ->and($array->itemName)
        ->toBe('item');
});

test('constructor defaults xmlAttributes to an empty flipped array when omitted', function (): void {
    $array = new PwgNamedArray([1, 2], 'item');

    expect($array->xmlAttributes)
        ->toBe([]);
});
