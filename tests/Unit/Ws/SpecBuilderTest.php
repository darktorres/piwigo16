<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Ws;

use PHPUnit\Framework\TestCase;
use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\OpenApi\SpecBuilder;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsType;

/**
 * Unit-tests for SpecBuilder — no DB, no HTTP bootstrap.
 * PwgServer is instantiated directly; methods are registered by hand.
 */
final class SpecBuilderTest extends TestCase
{
    private PwgServer $server;

    protected function setUp(): void
    {
        $this->server = new PwgServer();

        // Stub globals that addMethod / getMethods touch (none — it's pure array work).
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function build(): \Piwigo\Ws\OpenApi\OpenApiDocument
    {
        return (new SpecBuilder($this->server))->build();
    }

    // -------------------------------------------------------------------------
    // Document structure
    // -------------------------------------------------------------------------

    public function test_build_returns_openapi_31_version(): void
    {
        $doc = $this->build();
        self::assertSame('3.1.0', $doc->toArray()['openapi']);
    }

    public function test_build_info_title_is_piwigo_web_services(): void
    {
        $doc = $this->build();
        self::assertSame('Piwigo Web Services', $doc->toArray()['info']['title']);
    }

    public function test_paths_key_exists(): void
    {
        $doc = $this->build();
        self::assertArrayHasKey('paths', $doc->toArray());
    }

    public function test_to_json_produces_valid_json(): void
    {
        $this->server->addMethod('pwg.getVersion', fn () => null, null, 'Returns version.');
        $json = $this->build()->toJson();
        self::assertNotEmpty($json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame(0, json_last_error());
    }

    // -------------------------------------------------------------------------
    // Path generation
    // -------------------------------------------------------------------------

    public function test_registered_method_appears_as_path(): void
    {
        $this->server->addMethod('pwg.getVersion', fn () => null, null, 'Returns version.');
        $paths = $this->build()->toArray()['paths'];
        self::assertArrayHasKey('/ws/pwg.getVersion', $paths);
    }

    public function test_hidden_method_is_excluded_from_spec(): void
    {
        $this->server->addMethod(
            'pwg.internal',
            fn () => null,
            null,
            'Internal.',
            '',
            ['hidden' => true]
        );
        $paths = $this->build()->toArray()['paths'];
        self::assertArrayNotHasKey('/ws/pwg.internal', $paths);
    }

    public function test_post_only_method_is_documented_as_post(): void
    {
        $this->server->addMethod(
            'pwg.images.setInfo',
            fn () => null,
            null,
            'Set image info.',
            '',
            ['post_only' => true]
        );
        $path = $this->build()->toArray()['paths']['/ws/pwg.images.setInfo'];
        self::assertArrayHasKey('post', $path);
        self::assertArrayNotHasKey('get', $path);
    }

    public function test_non_post_method_is_documented_as_get(): void
    {
        $this->server->addMethod('pwg.getVersion', fn () => null, null, 'Returns version.');
        $path = $this->build()->toArray()['paths']['/ws/pwg.getVersion'];
        self::assertArrayHasKey('get', $path);
        self::assertArrayNotHasKey('post', $path);
    }

    public function test_operation_id_replaces_dots_with_underscores(): void
    {
        $this->server->addMethod('pwg.images.getInfo', fn () => null, null, 'Get image info.');
        $op = $this->build()->toArray()['paths']['/ws/pwg.images.getInfo']['get'];
        self::assertSame('pwg_images_getInfo', $op['operationId']);
    }

    // -------------------------------------------------------------------------
    // Tags inference
    // -------------------------------------------------------------------------

    public function test_two_part_method_name_uses_first_segment_as_tag(): void
    {
        $this->server->addMethod('reflection.getMethodList', fn () => null, null, '');
        $op = $this->build()->toArray()['paths']['/ws/reflection.getMethodList']['get'];
        self::assertContains('reflection', $op['tags']);
    }

    public function test_three_part_method_name_uses_second_segment_as_tag(): void
    {
        $this->server->addMethod('pwg.images.getInfo', fn () => null, null, 'Get image.');
        $op = $this->build()->toArray()['paths']['/ws/pwg.images.getInfo']['get'];
        self::assertContains('images', $op['tags']);
    }

    // -------------------------------------------------------------------------
    // Parameter → schema type mapping
    // -------------------------------------------------------------------------

    public function test_bool_param_maps_to_boolean_schema(): void
    {
        $this->server->addMethod(
            'pwg.test',
            fn () => null,
            ['recursive' => ['type' => WS_TYPE_BOOL, 'default' => false]],
            ''
        );
        $params = $this->build()->toArray()['paths']['/ws/pwg.test']['get']['parameters'];
        $recursive = array_values(array_filter($params, fn ($p) => $p['name'] === 'recursive'))[0];
        self::assertSame('boolean', $recursive['schema']['type']);
    }

    public function test_int_positive_notnull_maps_to_integer_minimum_1(): void
    {
        $this->server->addMethod(
            'pwg.test',
            fn () => null,
            ['image_id' => ['type' => WS_TYPE_ID]],
            ''
        );
        $params = $this->build()->toArray()['paths']['/ws/pwg.test']['get']['parameters'];
        $idParam = array_values(array_filter($params, fn ($p) => $p['name'] === 'image_id'))[0];
        self::assertSame('integer', $idParam['schema']['type']);
        self::assertSame(1, $idParam['schema']['minimum']);
    }

    public function test_float_param_maps_to_number_schema(): void
    {
        $this->server->addMethod(
            'pwg.test',
            fn () => null,
            ['rate' => ['type' => WS_TYPE_FLOAT]],
            ''
        );
        $params = $this->build()->toArray()['paths']['/ws/pwg.test']['get']['parameters'];
        $rateParam = array_values(array_filter($params, fn ($p) => $p['name'] === 'rate'))[0];
        self::assertSame('number', $rateParam['schema']['type']);
        self::assertSame('float', $rateParam['schema']['format']);
    }

    public function test_untyped_param_defaults_to_string_schema(): void
    {
        $this->server->addMethod(
            'pwg.test',
            fn () => null,
            ['username' => []],
            ''
        );
        $params = $this->build()->toArray()['paths']['/ws/pwg.test']['get']['parameters'];
        $userParam = array_values(array_filter($params, fn ($p) => $p['name'] === 'username'))[0];
        self::assertSame('string', $userParam['schema']['type']);
    }

    public function test_force_array_flag_wraps_schema_in_array(): void
    {
        $this->server->addMethod(
            'pwg.test',
            fn () => null,
            ['image_id' => ['flags' => WS_PARAM_FORCE_ARRAY, 'type' => WS_TYPE_ID]],
            ''
        );
        $params = $this->build()->toArray()['paths']['/ws/pwg.test']['get']['parameters'];
        $param = array_values(array_filter($params, fn ($p) => $p['name'] === 'image_id'))[0];
        self::assertSame('array', $param['schema']['type']);
        self::assertSame('integer', $param['schema']['items']['type']);
    }

    public function test_accept_array_flag_produces_oneOf_schema(): void
    {
        $this->server->addMethod(
            'pwg.test',
            fn () => null,
            ['ids' => ['flags' => WS_PARAM_ACCEPT_ARRAY, 'type' => WS_TYPE_ID]],
            ''
        );
        $params = $this->build()->toArray()['paths']['/ws/pwg.test']['get']['parameters'];
        $param = array_values(array_filter($params, fn ($p) => $p['name'] === 'ids'))[0];
        self::assertArrayHasKey('oneOf', $param['schema']);
        self::assertCount(2, $param['schema']['oneOf']);
    }

    public function test_maxvalue_is_included_as_maximum(): void
    {
        $this->server->addMethod(
            'pwg.test',
            fn () => null,
            ['per_page' => ['type' => WS_TYPE_INT | WS_TYPE_POSITIVE, 'default' => 100, 'maxValue' => 500]],
            ''
        );
        $params = $this->build()->toArray()['paths']['/ws/pwg.test']['get']['parameters'];
        $param = array_values(array_filter($params, fn ($p) => $p['name'] === 'per_page'))[0];
        self::assertSame(500, $param['schema']['maximum']);
    }

    // -------------------------------------------------------------------------
    // Required / optional
    // -------------------------------------------------------------------------

    public function test_required_param_has_required_true(): void
    {
        $this->server->addMethod(
            'pwg.test',
            fn () => null,
            ['image_id' => ['type' => WS_TYPE_ID]],
            ''
        );
        $params = $this->build()->toArray()['paths']['/ws/pwg.test']['get']['parameters'];
        $param = array_values(array_filter($params, fn ($p) => $p['name'] === 'image_id'))[0];
        self::assertTrue($param['required']);
    }

    public function test_optional_param_has_required_false(): void
    {
        $this->server->addMethod(
            'pwg.test',
            fn () => null,
            ['cat_id' => ['type' => WS_TYPE_ID, 'default' => null]],
            ''
        );
        $params = $this->build()->toArray()['paths']['/ws/pwg.test']['get']['parameters'];
        $param = array_values(array_filter($params, fn ($p) => $p['name'] === 'cat_id'))[0];
        self::assertFalse($param['required']);
    }

    // -------------------------------------------------------------------------
    // Admin-only metadata
    // -------------------------------------------------------------------------

    public function test_admin_only_method_carries_security_requirement(): void
    {
        $this->server->addMethod(
            'pwg.admin.op',
            fn () => null,
            null,
            'Admin operation.',
            '',
            ['admin_only' => true]
        );
        $op = $this->build()->toArray()['paths']['/ws/pwg.admin.op']['get'];
        self::assertArrayHasKey('security', $op);
        self::assertTrue($op['x-admin-only']);
    }

    // -------------------------------------------------------------------------
    // MethodDefinition typed-registration path
    // -------------------------------------------------------------------------

    public function test_register_method_def_appears_in_spec(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.images.getInfo',
            callback: fn () => null,
            description: 'Returns image info.',
            tags: ['images'],
        ));
        $paths = $this->build()->toArray()['paths'];
        self::assertArrayHasKey('/ws/pwg.images.getInfo', $paths);
    }

    public function test_register_explicit_tags_override_inference(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.images.getInfo',
            callback: fn () => null,
            description: 'Get image.',
            tags: ['custom-tag'],
        ));
        $op = $this->build()->toArray()['paths']['/ws/pwg.images.getInfo']['get'];
        self::assertContains('custom-tag', $op['tags']);
        self::assertNotContains('images', $op['tags']);
    }

    public function test_register_empty_tags_falls_back_to_inference(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.categories.getList',
            callback: fn () => null,
            description: 'List categories.',
            tags: [],
        ));
        $op = $this->build()->toArray()['paths']['/ws/pwg.categories.getList']['get'];
        self::assertContains('categories', $op['tags']);
    }

    public function test_register_requires_auth_sets_security(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.admin.op',
            callback: fn () => null,
            description: 'Admin.',
            requiresAuth: true,
        ));
        $op = $this->build()->toArray()['paths']['/ws/pwg.admin.op']['get'];
        self::assertArrayHasKey('security', $op);
        self::assertTrue($op['x-admin-only']);
    }

    public function test_register_post_only_uses_post_verb(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.images.setInfo',
            callback: fn () => null,
            description: 'Set info.',
            postOnly: true,
        ));
        $path = $this->build()->toArray()['paths']['/ws/pwg.images.setInfo'];
        self::assertArrayHasKey('post', $path);
        self::assertArrayNotHasKey('get', $path);
    }

    public function test_register_with_param_definitions(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.images.getInfo',
            callback: fn () => null,
            description: 'Get image info.',
            params: [
                ParamDefinition::required('image_id', WsType::Id->value),
                ParamDefinition::optional('comments_page', 0, WsType::Int->value | WsType::Positive->value),
            ],
        ));
        $op     = $this->build()->toArray()['paths']['/ws/pwg.images.getInfo']['get'];
        $params = $op['parameters'];

        $idParam = array_values(array_filter($params, fn ($p) => $p['name'] === 'image_id'))[0];
        self::assertTrue($idParam['required']);
        self::assertSame('integer', $idParam['schema']['type']);
        self::assertSame(1, $idParam['schema']['minimum']);

        $pageParam = array_values(array_filter($params, fn ($p) => $p['name'] === 'comments_page'))[0];
        self::assertFalse($pageParam['required']);
        self::assertSame(0, $pageParam['schema']['default']);
    }

    public function test_register_hidden_def_excluded_from_spec(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.internal',
            callback: fn () => null,
            description: 'Internal.',
            hidden: true,
        ));
        $paths = $this->build()->toArray()['paths'];
        self::assertArrayNotHasKey('/ws/pwg.internal', $paths);
    }

    public function test_register_method_is_invokable_via_invoke(): void
    {
        $called = false;
        $this->server->register(new MethodDefinition(
            name: 'pwg.test',
            callback: function (array $params) use (&$called): string {
                $called = true;
                return 'ok';
            },
            description: 'Test.',
        ));
        $result = $this->server->invoke('pwg.test', []);
        self::assertTrue($called, 'register() must store callback in $_methods so invoke() works');
        self::assertSame('ok', $result);
    }
}
