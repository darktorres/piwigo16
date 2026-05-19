<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Ws;

use PHPUnit\Framework\TestCase;
use Piwigo\Session\Session;
use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\OpenApi\ApiMethod;
use Piwigo\Ws\OpenApi\OpenApiDocument;
use Piwigo\Ws\OpenApi\SpecBuilder;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsParam;
use Piwigo\Ws\WsType;
use Symfony\Component\EventDispatcher\EventDispatcher as SymfonyEventDispatcher;

/**
 * Unit-tests for SpecBuilder — no DB, no HTTP bootstrap.
 * PwgServer is instantiated directly; methods are registered by hand.
 */
final class SpecBuilderTest extends TestCase
{
    private PwgServer $server;

    #[\Override]
    protected function setUp(): void
    {
        $this->server = new \ReflectionClass(PwgServer::class)->newInstanceWithoutConstructor();

        // PwgServer::invoke() now fires the ws_invoke_allowed event; provide a
        // bare dispatcher so the typed property is initialized. The DB-bound
        // WsInvokeAllowedSubscriber isn't wired here so the event no-ops.
        $dispatcherRef = new \ReflectionProperty($this->server, 'dispatcher');
        $dispatcherRef->setValue($this->server, new SymfonyEventDispatcher());

        // PwgServer::isAuthorizedMethodForAPIKEY() reads $session->connectedWith;
        // initialize an empty Session so the typed-property check succeeds.
        $sessionRef = new \ReflectionProperty($this->server, 'session');
        $sessionRef->setValue($this->server, Session::fromSuperglobal([]));

        // Stub globals that addMethod / getMethods touch (none — it's pure array work).
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function build(): OpenApiDocument
    {
        return new SpecBuilder($this->server)->build();
    }

    /** @return array<string, mixed> */
    private function getPaths(): array
    {
        $paths = $this->build()->toArray()['paths'];
        self::assertIsArray($paths);
        return array_combine(array_map(strval(...), array_keys($paths)), array_values($paths)) ?: [];
    }

    /** @return array<string, mixed> */
    private function getPath(string $wsPath): array
    {
        $paths = $this->getPaths();
        self::assertArrayHasKey($wsPath, $paths);
        $path = $paths[$wsPath];
        self::assertIsArray($path);
        return array_combine(array_map(strval(...), array_keys($path)), array_values($path)) ?: [];
    }

    /** @return array<string, mixed> */
    private function getOp(string $wsPath, string $verb = 'get'): array
    {
        $path = $this->getPath($wsPath);
        self::assertArrayHasKey($verb, $path);
        $op = $path[$verb];
        self::assertIsArray($op);
        return array_combine(array_map(strval(...), array_keys($op)), array_values($op)) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getParameters(string $wsPath, string $verb = 'get'): array
    {
        $op = $this->getOp($wsPath, $verb);
        $params = $op['parameters'];
        self::assertIsArray($params);
        return array_values(array_map(
            fn (mixed $p): array => is_array($p)
                ? (array_combine(array_map(strval(...), array_keys($p)), array_values($p)) ?: [])
                : [],
            $params
        ));
    }

    /** @return array<string, mixed> */
    private function findParam(string $wsPath, string $name, string $verb = 'get'): array
    {
        $params = $this->getParameters($wsPath, $verb);
        $found = array_values(array_filter($params, fn (array $p): bool => ($p['name'] ?? null) === $name));
        self::assertNotEmpty($found, "Parameter '$name' not found in $wsPath");
        return $found[0];
    }

    /** @return array<string, mixed> */
    private function getSchema(string $wsPath, string $paramName, string $verb = 'get'): array
    {
        $param = $this->findParam($wsPath, $paramName, $verb);
        $schema = $param['schema'];
        self::assertIsArray($schema);
        return array_combine(array_map(strval(...), array_keys($schema)), array_values($schema)) ?: [];
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
        $info = $this->build()->toArray()['info'];
        self::assertIsArray($info);
        self::assertSame('Piwigo Web Services', $info['title']);
    }

    public function test_paths_key_exists(): void
    {
        self::assertArrayHasKey('paths', $this->build()->toArray());
    }

    public function test_to_json_produces_valid_json(): void
    {
        $this->server->register(new MethodDefinition(name: 'pwg.getVersion', callback: fn (): null => null, description: 'Returns version.', tags: ['pwg']));
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
        $this->server->register(new MethodDefinition(name: 'pwg.getVersion', callback: fn (): null => null, description: 'Returns version.', tags: ['pwg']));
        self::assertArrayHasKey('/ws/pwg.getVersion', $this->getPaths());
    }

    public function test_hidden_method_is_excluded_from_spec(): void
    {
        $this->server->register(new MethodDefinition(name: 'pwg.internal', callback: fn (): null => null, description: 'Internal.', hidden: true));
        self::assertArrayNotHasKey('/ws/pwg.internal', $this->getPaths());
    }

    public function test_post_only_method_is_documented_as_post(): void
    {
        $this->server->register(new MethodDefinition(name: 'pwg.images.setInfo', callback: fn (): null => null, description: 'Set image info.', tags: ['images'], postOnly: true));
        $path = $this->getPath('/ws/pwg.images.setInfo');
        self::assertArrayHasKey('post', $path);
        self::assertArrayNotHasKey('get', $path);
    }

    public function test_non_post_method_is_documented_as_get(): void
    {
        $this->server->register(new MethodDefinition(name: 'pwg.getVersion', callback: fn (): null => null, description: 'Returns version.', tags: ['pwg']));
        $path = $this->getPath('/ws/pwg.getVersion');
        self::assertArrayHasKey('get', $path);
        self::assertArrayNotHasKey('post', $path);
    }

    public function test_operation_id_replaces_dots_with_underscores(): void
    {
        $this->server->register(new MethodDefinition(name: 'pwg.images.getInfo', callback: fn (): null => null, description: 'Get image info.', tags: ['images']));
        $op = $this->getOp('/ws/pwg.images.getInfo');
        self::assertSame('pwg_images_getInfo', $op['operationId']);
    }

    // -------------------------------------------------------------------------
    // Tags — explicit from MethodDefinition, inferred when tags: []
    // -------------------------------------------------------------------------

    public function test_two_part_method_name_uses_first_segment_as_tag_when_no_explicit_tags(): void
    {
        $this->server->register(new MethodDefinition(name: 'reflection.getMethodList', callback: fn (): null => null, tags: []));
        $op = $this->getOp('/ws/reflection.getMethodList');
        $tags = $op['tags'];
        self::assertIsArray($tags);
        self::assertContains('reflection', $tags);
    }

    public function test_three_part_method_name_uses_second_segment_as_tag_when_no_explicit_tags(): void
    {
        $this->server->register(new MethodDefinition(name: 'pwg.images.getInfo', callback: fn (): null => null, description: 'Get image.', tags: []));
        $op = $this->getOp('/ws/pwg.images.getInfo');
        $tags = $op['tags'];
        self::assertIsArray($tags);
        self::assertContains('images', $tags);
    }

    // -------------------------------------------------------------------------
    // Parameter → schema type mapping
    // -------------------------------------------------------------------------

    public function test_bool_param_maps_to_boolean_schema(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.test',
            callback: fn (): null => null,
            params: [ParamDefinition::optional(name: 'recursive', default: false, type: WsType::Bool->value)],
        ));
        self::assertSame('boolean', $this->getSchema('/ws/pwg.test', 'recursive')['type']);
    }

    public function test_int_positive_notnull_maps_to_integer_minimum_1(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.test',
            callback: fn (): null => null,
            params: [ParamDefinition::required(name: 'image_id', type: WsType::Id->value)],
        ));
        $schema = $this->getSchema('/ws/pwg.test', 'image_id');
        self::assertSame('integer', $schema['type']);
        self::assertSame(1, $schema['minimum']);
    }

    public function test_float_param_maps_to_number_schema(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.test',
            callback: fn (): null => null,
            params: [ParamDefinition::required(name: 'rate', type: WsType::Float->value)],
        ));
        $schema = $this->getSchema('/ws/pwg.test', 'rate');
        self::assertSame('number', $schema['type']);
        self::assertSame('float', $schema['format']);
    }

    public function test_untyped_param_defaults_to_string_schema(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.test',
            callback: fn (): null => null,
            params: [ParamDefinition::required('username')],
        ));
        self::assertSame('string', $this->getSchema('/ws/pwg.test', 'username')['type']);
    }

    public function test_force_array_flag_wraps_schema_in_array(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.test',
            callback: fn (): null => null,
            params: [ParamDefinition::required(name: 'image_id', type: WsType::Id->value, flags: WsParam::ForceArray->value)],
        ));
        $schema = $this->getSchema('/ws/pwg.test', 'image_id');
        self::assertSame('array', $schema['type']);
        $items = $schema['items'];
        self::assertIsArray($items);
        self::assertSame('integer', $items['type']);
    }

    public function test_accept_array_flag_produces_oneOf_schema(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.test',
            callback: fn (): null => null,
            params: [ParamDefinition::required(name: 'ids', type: WsType::Id->value, flags: WsParam::AcceptArray->value)],
        ));
        $schema = $this->getSchema('/ws/pwg.test', 'ids');
        self::assertArrayHasKey('oneOf', $schema);
        $oneOf = $schema['oneOf'];
        self::assertIsArray($oneOf);
        self::assertCount(2, $oneOf);
    }

    public function test_maxvalue_is_included_as_maximum(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.test',
            callback: fn (): null => null,
            params: [ParamDefinition::optional(name: 'per_page', default: 100, type: WsType::Int->value | WsType::Positive->value, maxValue: 500)],
        ));
        self::assertSame(500, $this->getSchema('/ws/pwg.test', 'per_page')['maximum']);
    }

    // -------------------------------------------------------------------------
    // Required / optional
    // -------------------------------------------------------------------------

    public function test_required_param_has_required_true(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.test',
            callback: fn (): null => null,
            params: [ParamDefinition::required(name: 'image_id', type: WsType::Id->value)],
        ));
        self::assertTrue($this->findParam('/ws/pwg.test', 'image_id')['required']);
    }

    public function test_optional_param_has_required_false(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.test',
            callback: fn (): null => null,
            params: [ParamDefinition::optional(name: 'cat_id', default: null, type: WsType::Id->value)],
        ));
        self::assertFalse($this->findParam('/ws/pwg.test', 'cat_id')['required']);
    }

    // -------------------------------------------------------------------------
    // Admin-only metadata
    // -------------------------------------------------------------------------

    public function test_admin_only_method_carries_security_requirement(): void
    {
        $this->server->register(new MethodDefinition(name: 'pwg.admin.op', callback: fn (): null => null, description: 'Admin operation.', requiresAuth: true));
        $op = $this->getOp('/ws/pwg.admin.op');
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
            callback: fn (): null => null,
            description: 'Returns image info.',
            tags: ['images'],
        ));
        self::assertArrayHasKey('/ws/pwg.images.getInfo', $this->getPaths());
    }

    public function test_register_explicit_tags_override_inference(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.images.getInfo',
            callback: fn (): null => null,
            description: 'Get image.',
            tags: ['custom-tag'],
        ));
        $op = $this->getOp('/ws/pwg.images.getInfo');
        $tags = $op['tags'];
        self::assertIsArray($tags);
        self::assertContains('custom-tag', $tags);
        self::assertNotContains('images', $tags);
    }

    public function test_register_empty_tags_falls_back_to_inference(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.categories.getList',
            callback: fn (): null => null,
            description: 'List categories.',
            tags: [],
        ));
        $op = $this->getOp('/ws/pwg.categories.getList');
        $tags = $op['tags'];
        self::assertIsArray($tags);
        self::assertContains('categories', $tags);
    }

    public function test_register_requires_auth_sets_security(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.admin.op',
            callback: fn (): null => null,
            description: 'Admin.',
            requiresAuth: true,
        ));
        $op = $this->getOp('/ws/pwg.admin.op');
        self::assertArrayHasKey('security', $op);
        self::assertTrue($op['x-admin-only']);
    }

    public function test_register_post_only_uses_post_verb(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.images.setInfo',
            callback: fn (): null => null,
            description: 'Set info.',
            postOnly: true,
        ));
        $path = $this->getPath('/ws/pwg.images.setInfo');
        self::assertArrayHasKey('post', $path);
        self::assertArrayNotHasKey('get', $path);
    }

    public function test_register_with_param_definitions(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.images.getInfo',
            callback: fn (): null => null,
            description: 'Get image info.',
            params: [
                ParamDefinition::required('image_id', WsType::Id->value),
                ParamDefinition::optional('comments_page', 0, WsType::Int->value | WsType::Positive->value),
            ],
        ));

        $idParam = $this->findParam('/ws/pwg.images.getInfo', 'image_id');
        self::assertTrue($idParam['required']);
        $idSchema = $this->getSchema('/ws/pwg.images.getInfo', 'image_id');
        self::assertSame('integer', $idSchema['type']);
        self::assertSame(1, $idSchema['minimum']);

        $pageParam = $this->findParam('/ws/pwg.images.getInfo', 'comments_page');
        self::assertFalse($pageParam['required']);
        $pageSchema = $this->getSchema('/ws/pwg.images.getInfo', 'comments_page');
        self::assertSame(0, $pageSchema['default']);
    }

    public function test_register_hidden_def_excluded_from_spec(): void
    {
        $this->server->register(new MethodDefinition(
            name: 'pwg.internal',
            callback: fn (): null => null,
            description: 'Internal.',
            hidden: true,
        ));
        self::assertArrayNotHasKey('/ws/pwg.internal', $this->getPaths());
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

    // -------------------------------------------------------------------------
    // #[ApiMethod] reflection (B16)
    // -------------------------------------------------------------------------

    public function test_api_method_attribute_summary_overrides_description(): void
    {
        $endpoint = new SpecBuilderFakeEndpoint();
        $this->server->register(new MethodDefinition(
            name: 'pwg.fake.getInfo',
            callback: $endpoint->getInfo(...),
            description: 'Stale description from registration.',
            tags: ['stale-tag'],
        ));

        $op = $this->getOp('/ws/pwg.fake.getInfo');
        self::assertSame('Attribute summary wins.', $op['summary']);
    }

    public function test_api_method_attribute_tags_override_definition_tags(): void
    {
        $endpoint = new SpecBuilderFakeEndpoint();
        $this->server->register(new MethodDefinition(
            name: 'pwg.fake.getInfo',
            callback: $endpoint->getInfo(...),
            tags: ['stale-tag'],
        ));

        $op   = $this->getOp('/ws/pwg.fake.getInfo');
        $tags = $op['tags'];
        self::assertIsArray($tags);
        self::assertContains('fakes', $tags);
        self::assertNotContains('stale-tag', $tags);
    }

    public function test_method_without_api_method_attribute_falls_back_to_description(): void
    {
        $endpoint = new SpecBuilderFakeEndpoint();
        $this->server->register(new MethodDefinition(
            name: 'pwg.fake.plain',
            callback: $endpoint->plain(...),
            description: 'Description used because no attribute.',
            tags: ['fakes'],
        ));

        self::assertSame(
            'Description used because no attribute.',
            $this->getOp('/ws/pwg.fake.plain')['summary'],
        );
    }

    public function test_anonymous_closure_callback_falls_back_to_description(): void
    {
        // Lambdas have no method to reflect — make sure the reflection helper
        // gracefully returns null and registration metadata still wins.
        $this->server->register(new MethodDefinition(
            name: 'pwg.fake.lambda',
            callback: fn (): null => null,
            description: 'Lambda fallback.',
            tags: ['fakes'],
        ));

        self::assertSame('Lambda fallback.', $this->getOp('/ws/pwg.fake.lambda')['summary']);
    }

    public function test_array_callback_supports_api_method_attribute(): void
    {
        $endpoint = new SpecBuilderFakeEndpoint();
        $this->server->register(new MethodDefinition(
            name: 'pwg.fake.arrayCallback',
            callback: [$endpoint, 'getInfo'],
        ));

        $op = $this->getOp('/ws/pwg.fake.arrayCallback');
        self::assertSame('Attribute summary wins.', $op['summary']);
    }
}

/**
 * Fixture endpoint class with one decorated and one plain method, used to
 * exercise SpecBuilder's #[ApiMethod] reflection path.
 *
 * @psalm-suppress UnusedClass — referenced via first-class callable / array
 *                                callback in tests of this file only.
 */
final class SpecBuilderFakeEndpoint
{
    #[ApiMethod(summary: 'Attribute summary wins.', tags: ['fakes'])]
    public function getInfo(): null
    {
        return null;
    }

    public function plain(): null
    {
        return null;
    }
}
