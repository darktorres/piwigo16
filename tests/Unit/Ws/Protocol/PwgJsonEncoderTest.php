<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Ws\Protocol;

use PHPUnit\Framework\TestCase;
use Piwigo\Ws\Protocol\PwgJsonEncoder;
use Piwigo\Ws\PwgError;

final class PwgJsonEncoderTest extends TestCase
{
    private PwgJsonEncoder $encoder;

    #[\Override]
    protected function setUp(): void
    {
        $this->encoder = new PwgJsonEncoder();
    }

    public function testGetContentType(): void
    {
        self::assertSame('text/plain', $this->encoder->getContentType());
    }

    public function testEncodeErrorResponse(): void
    {
        $err = new PwgError(404, 'not found');
        $json = $this->encoder->encodeResponse($err);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame('fail', $decoded['stat']);
        self::assertSame(404, $decoded['err']);
        self::assertSame('not found', $decoded['message']);
    }

    public function testEncodeStringResponse(): void
    {
        $json = $this->encoder->encodeResponse('hello');
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame('ok', $decoded['stat']);
        self::assertSame('hello', $decoded['result']);
    }

    public function testEncodeArrayResponse(): void
    {
        $json = $this->encoder->encodeResponse(['a' => 1, 'b' => 2]);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame('ok', $decoded['stat']);
        $result = $decoded['result'];
        self::assertIsArray($result);
        self::assertSame(1, $result['a']);
    }

    public function testEncodeNullResponse(): void
    {
        $json = $this->encoder->encodeResponse(null);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame('ok', $decoded['stat']);
        self::assertNull($decoded['result']);
    }
}
