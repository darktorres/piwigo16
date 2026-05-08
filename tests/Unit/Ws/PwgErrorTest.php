<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Ws;

use PHPUnit\Framework\TestCase;
use Piwigo\Ws\PwgError;

final class PwgErrorTest extends TestCase
{
    public function test_code_and_message_round_trip(): void
    {
        $e = new PwgError(1003, 'Invalid parameter');
        self::assertSame(1003, $e->code());
        self::assertSame('Invalid parameter', $e->message());
    }

    public function test_http_status_codes_do_not_throw(): void
    {
        // ServiceLocator::get(HtmlService::class)->setStatusHeader() is stubbed in tests/bootstrap.php for unit runs.
        $e = new PwgError(404, 'Not found');
        self::assertSame(404, $e->code());
        self::assertSame('Not found', $e->message());
    }
}
