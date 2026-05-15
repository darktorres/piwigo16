<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Ws;

use PHPUnit\Framework\TestCase;
use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsParam;
use Piwigo\Ws\WsType;

/**
 * Unit tests for PwgServer method registration and introspection.
 *
 * PwgServer::run(), sendResponse(), and the reflection methods require a live
 * request handler and DB. These tests cover only the parts that work in
 * isolation: register(), hasMethod(), getMethodDescription(), and
 * getMethodSignature().
 */
final class PwgServerTest extends TestCase
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    private PwgServer $server;

    #[\Override]
    protected function setUp(): void
    {
        $this->server = new \ReflectionClass(PwgServer::class)->newInstanceWithoutConstructor();
    }

    // ── hasMethod() ──────────────────────────────────────────────────────────

    public function test_has_method_returns_false_before_registration(): void
    {
        self::assertFalse($this->server->hasMethod('pwg.foo.bar'));
    }

    public function test_has_method_returns_true_after_registration(): void
    {
        $this->server->register(new MethodDefinition(name: 'pwg.my.method', callback: 'some_callback'));
        self::assertTrue($this->server->hasMethod('pwg.my.method'));
    }

    // ── register() + getMethodDescription() ─────────────────────────────────

    public function test_get_method_description_returns_empty_for_unknown(): void
    {
        self::assertSame('', $this->server->getMethodDescription('no.such.method'));
    }

    public function test_get_method_description_returns_registered_description(): void
    {
        $this->server->register(new MethodDefinition(
            name:        'pwg.test',
            callback:    'callback',
            description: 'Test description',
        ));
        self::assertSame('Test description', $this->server->getMethodDescription('pwg.test'));
    }

    // ── register() + getMethodSignature() ────────────────────────────────────

    public function test_get_method_signature_returns_empty_for_unknown(): void
    {
        self::assertSame([], $this->server->getMethodSignature('no.such.method'));
    }

    public function test_register_with_no_params_produces_empty_signature(): void
    {
        $this->server->register(new MethodDefinition(name: 'pwg.no_params', callback: 'callback'));
        self::assertSame([], $this->server->getMethodSignature('pwg.no_params'));
    }

    public function test_register_required_param_preserves_type(): void
    {
        $this->server->register(new MethodDefinition(
            name:     'pwg.typed',
            callback: 'callback',
            params:   [ParamDefinition::required(name: 'image_id', type: WsType::Int->value | WsType::Positive->value)],
        ));
        $sig = $this->server->getMethodSignature('pwg.typed');

        self::assertArrayHasKey('image_id', $sig);
        self::assertSame(WsType::Int->value | WsType::Positive->value, $sig['image_id']['type']);
        self::assertSame(0, $sig['image_id']['flags'] & WsParam::Optional->value);
    }

    public function test_register_optional_param_sets_optional_flag_and_default(): void
    {
        $this->server->register(new MethodDefinition(
            name:     'pwg.optional_param',
            callback: 'callback',
            params:   [ParamDefinition::optional(name: 'limit', default: 100, type: WsType::Int->value)],
        ));
        $sig = $this->server->getMethodSignature('pwg.optional_param');

        self::assertArrayHasKey('limit', $sig);
        self::assertTrue((bool) ($sig['limit']['flags'] & WsParam::Optional->value));
        self::assertArrayHasKey('default', $sig['limit']);
        self::assertSame(100, $sig['limit']['default'] ?? null);
    }

    public function test_register_optionalflag_param_sets_optional_without_default(): void
    {
        $this->server->register(new MethodDefinition(
            name:     'pwg.optional_no_default',
            callback: 'callback',
            params:   [ParamDefinition::optionalFlag(name: 'group_id', type: WsType::Id->value)],
        ));
        $sig = $this->server->getMethodSignature('pwg.optional_no_default');

        self::assertArrayHasKey('group_id', $sig);
        self::assertTrue((bool) ($sig['group_id']['flags'] & WsParam::Optional->value));
        self::assertArrayNotHasKey('default', $sig['group_id']);
    }

    public function test_multiple_methods_registered_independently(): void
    {
        $this->server->register(new MethodDefinition(name: 'pwg.alpha', callback: 'cb_a', description: 'Alpha'));
        $this->server->register(new MethodDefinition(name: 'pwg.beta', callback: 'cb_b', description: 'Beta'));

        self::assertTrue($this->server->hasMethod('pwg.alpha'));
        self::assertTrue($this->server->hasMethod('pwg.beta'));
        self::assertSame('Alpha', $this->server->getMethodDescription('pwg.alpha'));
        self::assertSame('Beta', $this->server->getMethodDescription('pwg.beta'));
    }

    // ── register() + getMethodDefs() ─────────────────────────────────────────

    public function test_register_stores_method_definition_retrievable_via_get_method_defs(): void
    {
        $def = new MethodDefinition(
            name:        'pwg.images.getInfo',
            callback:    'ws_images_getInfo',
            description: 'Returns image info.',
            tags:        ['images'],
            requiresAuth: false,
        );
        $this->server->register($def);

        $defs = $this->server->getMethodDefs();
        self::assertArrayHasKey('pwg.images.getInfo', $defs);
        self::assertSame($def, $defs['pwg.images.getInfo']);
    }

    public function test_register_method_def_tags_accessible(): void
    {
        $this->server->register(new MethodDefinition(
            name:     'pwg.images.rate',
            callback: 'ws_images_rate',
            tags:     ['images'],
        ));
        $defs = $this->server->getMethodDefs();
        self::assertSame(['images'], $defs['pwg.images.rate']->tags);
    }
}
