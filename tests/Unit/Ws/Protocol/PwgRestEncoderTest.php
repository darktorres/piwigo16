<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Ws\Protocol;

use PHPUnit\Framework\TestCase;
use Piwigo\Ws\Protocol\PwgRestEncoder;
use Piwigo\Ws\PwgError;

final class PwgRestEncoderTest extends TestCase
{
    private PwgRestEncoder $encoder;

    #[\Override]
    protected function setUp(): void
    {
        $this->encoder = new PwgRestEncoder();
    }

    public function testGetContentType(): void
    {
        self::assertSame('text/xml', $this->encoder->getContentType());
    }

    public function testEncodeErrorResponse(): void
    {
        $err = new PwgError(403, 'forbidden');
        $xml = $this->encoder->encodeResponse($err);
        self::assertStringContainsString('stat="fail"', $xml);
        self::assertStringContainsString('code="403"', $xml);
        self::assertStringContainsString('forbidden', $xml);
    }

    public function testEncodeSuccessResponse(): void
    {
        $xml = $this->encoder->encodeResponse('ok_value');
        self::assertStringContainsString('stat="ok"', $xml);
        self::assertStringContainsString('ok_value', $xml);
    }

    public function testEncodeStructResponse(): void
    {
        $xml = $this->encoder->encodeResponse(['name' => 'Alice', 'age' => 30]);
        self::assertStringContainsString('stat="ok"', $xml);
        self::assertStringContainsString('Alice', $xml);
        self::assertStringContainsString('30', $xml);
    }

    public function testEncodeArrayOfItems(): void
    {
        $xml = $this->encoder->encodeResponse(['alpha', 'beta', 'gamma']);
        self::assertStringContainsString('stat="ok"', $xml);
        self::assertStringContainsString('alpha', $xml);
        self::assertStringContainsString('beta', $xml);
    }

    public function testEncodeErrorEscapesHtml(): void
    {
        $err = new PwgError(null, '<script>alert(1)</script>');
        $xml = $this->encoder->encodeResponse($err);
        self::assertStringNotContainsString('<script>', $xml);
        self::assertStringContainsString('&lt;script&gt;', $xml);
    }
}
