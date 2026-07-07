<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsCaddieTest extends ContractTestCase
{
    public function test_caddie_add_returns_integer(): void
    {
        $response = $this->wsAdmin('pwg.caddie.add', ['image_id' => [1]]);

        // caddie.add returns a plain integer (count of items added)
        self::assertSame('ok', $response['stat']);
        self::assertIsInt($response['result']);
    }

    public function test_caddie_add_returns_zero_for_duplicate(): void
    {
        // add the same image twice; second call should return 0
        $this->wsAdmin('pwg.caddie.add', ['image_id' => [1]]);
        $response = $this->wsAdmin('pwg.caddie.add', ['image_id' => [1]]);

        self::assertSame(0, $response['result']);
    }
}
