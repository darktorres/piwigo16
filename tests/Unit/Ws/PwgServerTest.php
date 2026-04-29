<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Ws;

use PHPUnit\Framework\TestCase;
use Piwigo\Ws\PwgServer;

/**
 * Unit tests for PwgServer method registration and introspection.
 *
 * PwgServer::run(), sendResponse(), and the reflection methods require a live
 * request handler and DB. These tests cover only the parts that work in
 * isolation: addMethod(), hasMethod(), getMethodDescription(), and
 * getMethodSignature().
 */
final class PwgServerTest extends TestCase
{
    private PwgServer $server;

    protected function setUp(): void
    {
        $this->server = new PwgServer();
    }

    // ── hasMethod() ──────────────────────────────────────────────────────────

    public function test_has_method_returns_false_before_registration(): void
    {
        self::assertFalse($this->server->hasMethod('pwg.foo.bar'));
    }

    public function test_has_method_returns_true_after_registration(): void
    {
        $this->server->addMethod('pwg.my.method', 'some_callback');
        self::assertTrue($this->server->hasMethod('pwg.my.method'));
    }

    // ── addMethod() + getMethodDescription() ────────────────────────────────

    public function test_get_method_description_returns_empty_for_unknown(): void
    {
        self::assertSame('', $this->server->getMethodDescription('no.such.method'));
    }

    public function test_get_method_description_returns_registered_description(): void
    {
        $this->server->addMethod('pwg.test', 'callback', [], 'Test description');
        self::assertSame('Test description', $this->server->getMethodDescription('pwg.test'));
    }

    // ── addMethod() + getMethodSignature() ───────────────────────────────────

    public function test_get_method_signature_returns_empty_for_unknown(): void
    {
        self::assertSame([], $this->server->getMethodSignature('no.such.method'));
    }

    public function test_add_method_with_no_params_registers_empty_signature(): void
    {
        $this->server->addMethod('pwg.no_params', 'callback');
        self::assertSame([], $this->server->getMethodSignature('pwg.no_params'));
    }

    public function test_add_method_with_positional_params_normalizes_to_map(): void
    {
        // Positional array: ['photo_id', 'size'] → map with default flags.
        $this->server->addMethod('pwg.positional', 'callback', ['photo_id', 'size']);
        $sig = $this->server->getMethodSignature('pwg.positional');

        self::assertArrayHasKey('photo_id', $sig);
        self::assertArrayHasKey('size', $sig);
        self::assertSame(0, $sig['photo_id']['flags']);
        self::assertSame(0, $sig['photo_id']['type']);
    }

    public function test_add_method_with_typed_params_preserves_type(): void
    {
        $this->server->addMethod('pwg.typed', 'callback', [
            'image_id' => ['type' => WS_TYPE_INT | WS_TYPE_POSITIVE],
        ]);
        $sig = $this->server->getMethodSignature('pwg.typed');

        self::assertArrayHasKey('image_id', $sig);
        self::assertSame(WS_TYPE_INT | WS_TYPE_POSITIVE, $sig['image_id']['type']);
    }

    public function test_add_method_with_default_sets_optional_flag(): void
    {
        $this->server->addMethod('pwg.optional_param', 'callback', [
            'limit' => ['default' => 100, 'type' => WS_TYPE_INT],
        ]);
        $sig = $this->server->getMethodSignature('pwg.optional_param');

        self::assertArrayHasKey('limit', $sig);
        self::assertTrue((bool) ($sig['limit']['flags'] & WS_PARAM_OPTIONAL));
    }

    public function test_multiple_methods_registered_independently(): void
    {
        $this->server->addMethod('pwg.alpha', 'cb_a', [], 'Alpha');
        $this->server->addMethod('pwg.beta', 'cb_b', [], 'Beta');

        self::assertTrue($this->server->hasMethod('pwg.alpha'));
        self::assertTrue($this->server->hasMethod('pwg.beta'));
        self::assertSame('Alpha', $this->server->getMethodDescription('pwg.alpha'));
        self::assertSame('Beta', $this->server->getMethodDescription('pwg.beta'));
    }
}
