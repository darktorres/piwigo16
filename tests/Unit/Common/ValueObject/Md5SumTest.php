<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Common\ValueObject;

use Piwigo\Common\ValueObject\Md5Sum;
use Piwigo\Tests\Unit\Common\ValueObject\Contract\StringVoContract;

/** @extends StringVoContract<Md5Sum> */
final class Md5SumTest extends StringVoContract
{
    #[\Override]
    protected static function voClass(): string
    {
        return Md5Sum::class;
    }

    #[\Override]
    protected static function validSample(): string
    {
        // md5('hello world')
        return '5eb63bbbe01eeed093cb22bb8f5acdc3';
    }

    /** @return iterable<string, array{string}> */
    #[\Override]
    public static function invalidSamples(): iterable
    {
        yield 'empty'              => [''];
        yield 'too short (31)'     => [str_repeat('a', 31)];
        yield 'too long (33)'      => [str_repeat('a', 33)];
        yield 'uppercase hex'      => ['5EB63BBBE01EEED093CB22BB8F5ACDC3'];
        yield 'mixed case'         => ['5Eb63bbbe01eeed093cb22bb8f5acdc3'];
        yield 'non hex char'       => ['5eb63bbbe01eeed093cb22bb8f5acdcG'];
        yield 'with whitespace'    => [' 5eb63bbbe01eeed093cb22bb8f5acdc3'];
    }

    public function testProducedByMd5IsAccepted(): void
    {
        // Locks the lowercase-hex contract against any future PHP md5() change.
        $digest = md5('piwigo');
        self::assertNotNull(Md5Sum::tryFrom($digest));
        self::assertSame($digest, (string) Md5Sum::from($digest));
    }
}
