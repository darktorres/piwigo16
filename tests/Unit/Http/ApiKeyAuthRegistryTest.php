<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Piwigo\Http\ApiKeyAuthRegistry;

final class ApiKeyAuthRegistryTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        ApiKeyAuthRegistry::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        ApiKeyAuthRegistry::reset();
    }

    public function test_default_is_not_api_key_auth(): void
    {
        $this->assertFalse(ApiKeyAuthRegistry::isApiKeyAuth());
    }

    public function test_mark_sets_flag(): void
    {
        ApiKeyAuthRegistry::markApiKeyAuth();
        $this->assertTrue(ApiKeyAuthRegistry::isApiKeyAuth());
    }

    public function test_reset_clears_flag(): void
    {
        ApiKeyAuthRegistry::markApiKeyAuth();
        ApiKeyAuthRegistry::reset();
        $this->assertFalse(ApiKeyAuthRegistry::isApiKeyAuth());
    }
}
