<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Ws\Encoder;

use PHPUnit\Framework\TestCase;
use Piwigo\Ws\Encoder\PwgResponseEncoder;
use Piwigo\Ws\PwgNamedArray;

final class PwgResponseEncoderTest extends TestCase
{
    public function testIsStructReturnsTrueForAssociativeArray(): void
    {
        $data = ['a' => 1, 'b' => 2];
        self::assertTrue(PwgResponseEncoder::isStruct($data));
    }

    public function testIsStructReturnsFalseForIndexedArray(): void
    {
        $data = [1, 2, 3];
        self::assertFalse(PwgResponseEncoder::isStruct($data));
    }

    public function testIsStructReturnsTrueForEmptyArray(): void
    {
        // range(0, -1) = [0, -1] in PHP, which does not equal array_keys([]) = [],
        // so an empty array is treated as a struct by this implementation.
        $data = [];
        self::assertTrue(PwgResponseEncoder::isStruct($data));
    }

    public function testIsStructReturnsFalseForNonArray(): void
    {
        $data = 'string';
        self::assertFalse(PwgResponseEncoder::isStruct($data));
    }

    public function testFlattenResponseUnwrapsNamedArray(): void
    {
        $content = ['item1', 'item2'];
        $named = new PwgNamedArray($content, 'item');
        $value = $named;
        PwgResponseEncoder::flattenResponse($value);
        /** @var mixed $value */
        self::assertSame($content, $value);
    }

    public function testFlattenResponseNestedUnwrap(): void
    {
        $content = ['x', 'y'];
        $value = ['key' => new PwgNamedArray($content, 'x')];
        PwgResponseEncoder::flattenResponse($value);
        /** @var array<mixed> $value */
        self::assertSame($content, $value['key']);
    }

    public function testFlattenResponseLeavesScalarsUnchanged(): void
    {
        $value = 'hello';
        PwgResponseEncoder::flattenResponse($value);
        self::assertSame('hello', $value);
    }
}
