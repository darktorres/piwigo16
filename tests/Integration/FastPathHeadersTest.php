<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

/**
 * The four fast-path branches in `index.php` (install, upgrade,
 * upgrade_feed, i/) short-circuit before the PSR-15 pipeline runs, so
 * `SecurityHeadersMiddleware` does not attach to their responses.
 * Instead they call `SecurityHeaders::emitDirect()` themselves. This
 * test exercises the live install wizard path and asserts the
 * load-bearing headers are present.
 */
final class FastPathHeadersTest extends IntegrationTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->requireBaseUrl();
    }

    public function test_install_wizard_carries_security_headers(): void
    {
        $headers = $this->headHeaders($this->baseUrl . '/index.php?/install');

        self::assertStringContainsString("content-security-policy: default-src 'self'", $headers);
        self::assertStringContainsString('x-frame-options: sameorigin', $headers);
        self::assertStringContainsString('x-content-type-options: nosniff', $headers);
        self::assertStringContainsString('referrer-policy: strict-origin-when-cross-origin', $headers);
    }

    /** @param non-empty-string $url */
    private function headHeaders(string $url): string
    {
        $ch = curl_init();
        self::assertNotFalse($ch, 'curl_init failed');
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => $this->testHeader(),
        ]);
        $result = curl_exec($ch);
        self::assertIsString($result);
        return strtolower($result);
    }
}
