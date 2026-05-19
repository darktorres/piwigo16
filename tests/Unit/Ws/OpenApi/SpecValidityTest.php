<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Ws\OpenApi;

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Parameter;
use cebe\openapi\spec\PathItem;
use cebe\openapi\spec\Paths;
use cebe\openapi\spec\Schema;
use PHPUnit\Framework\TestCase;
use Piwigo\Event\Ws\WsMethodsRegistering;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\User;
use Piwigo\Ws\Method\CategoriesEndpoints;
use Piwigo\Ws\Method\GeneralEndpoints;
use Piwigo\Ws\Method\ImagesEndpoints;
use Piwigo\Ws\Method\TagsEndpoints;
use Piwigo\Ws\Method\UsersEndpoints;
use Piwigo\Ws\OpenApi\SpecBuilder;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsMethodRegistrar;
use Symfony\Component\EventDispatcher\EventDispatcher as SymfonyEventDispatcher;

/**
 * CI gate for the live OpenAPI document.
 *
 * Boots the real WsMethodRegistrar against a bare PwgServer and validates
 * the resulting 3.1 spec with cebe/php-openapi. Endpoints and
 * PermissionService are instantiated via reflection so we avoid the DB
 * the real container would require — registration only takes method
 * handles via first-class callable syntax, so the constructors never
 * run.
 *
 * If any endpoint produces a malformed OpenAPI fragment (bad path, bad
 * schema, missing required field), this test fails. Same gate the CI
 * pipeline relies on for the production /ws/openapi.json output.
 */
final class SpecValidityTest extends TestCase
{
    private PwgServer $server;

    #[\Override]
    protected function setUp(): void
    {
        // PwgServer needs a dispatcher initialized; everything else is private state
        // that registration doesn't touch.
        $this->server = new \ReflectionClass(PwgServer::class)->newInstanceWithoutConstructor();
        $dispatcherRef = new \ReflectionProperty($this->server, 'dispatcher');
        $dispatcherRef->setValue($this->server, new SymfonyEventDispatcher());

        // Registrar reads CurrentUser::get()->rawAttributes for a default value
        // on pwg.images.addComment. Seed a guest user so it lands.
        CurrentUser::set(new User(
            id: 0,
            username: 'spec_validity_test',
            email: '',
            language: 'en_US',
            theme: 'elegant',
            status: 'guest',
            enabledHigh: false,
            rawAttributes: ['username' => 'spec_validity_test'],
        ));
    }

    #[\Override]
    protected function tearDown(): void
    {
        CurrentUser::reset();
    }

    public function test_real_registrar_emits_validated_openapi_31_spec(): void
    {
        $openApi = $this->loadRealSpec();
        self::assertSame('3.1.0', $openApi->openapi);
        self::assertTrue(
            $openApi->validate(),
            "Generated spec failed cebe validation:\n" . implode("\n", $openApi->getErrors()),
        );

        // Sanity: the registrar emits ~94 method paths.
        self::assertGreaterThan(80, count($this->paths($openApi)));
    }

    public function test_decorated_endpoint_summary_survives_real_pipeline(): void
    {
        $openApi = $this->loadRealSpec();
        self::assertTrue($openApi->validate(), implode("\n", $openApi->getErrors()));

        // pwg.images.getInfo carries #[ApiMethod(summary: 'Returns information about an image.', tags: ['images'])]
        $paths = $this->paths($openApi);
        self::assertArrayHasKey('/ws/pwg.images.getInfo', $paths);
        $op = $paths['/ws/pwg.images.getInfo']->get;
        self::assertNotNull($op);
        self::assertSame('Returns information about an image.', $op->summary);
        self::assertContains('images', $op->tags);
    }

    public function test_post_only_methods_are_documented_as_post(): void
    {
        $paths = $this->paths($this->loadRealSpec());
        self::assertArrayHasKey('/ws/pwg.session.login', $paths);
        $path = $paths['/ws/pwg.session.login'];
        self::assertNotNull($path->post, 'pwg.session.login must expose POST');
        self::assertNull($path->get, 'pwg.session.login must NOT expose GET');
    }

    public function test_admin_only_methods_carry_security_requirement(): void
    {
        $paths = $this->paths($this->loadRealSpec());
        self::assertArrayHasKey('/ws/pwg.getInfos', $paths);
        $op = $paths['/ws/pwg.getInfos']->get;
        self::assertNotNull($op);
        self::assertNotEmpty($op->security, 'admin-only operations must declare security');
    }

    public function test_every_operation_has_an_operation_id(): void
    {
        foreach ($this->paths($this->loadRealSpec()) as $pathStr => $item) {
            foreach (['get', 'post'] as $verb) {
                $op = $item->$verb;
                if ($op === null) {
                    continue;
                }
                self::assertNotEmpty(
                    $op->operationId,
                    "Operation $verb $pathStr is missing operationId",
                );
            }
        }
    }

    public function test_every_path_parameter_carries_a_typed_schema(): void
    {
        foreach ($this->paths($this->loadRealSpec()) as $pathStr => $item) {
            foreach (['get', 'post'] as $verb) {
                $op = $item->$verb;
                if ($op === null) {
                    continue;
                }
                foreach ($op->parameters as $param) {
                    // SpecBuilder never emits $ref parameters, only inline ones.
                    self::assertInstanceOf(Parameter::class, $param);
                    self::assertInstanceOf(
                        Schema::class,
                        $param->schema,
                        "$verb $pathStr parameter '$param->name' has no schema",
                    );
                }
            }
        }
    }

    private function loadRealSpec(): OpenApi
    {
        $registrar = $this->buildRegistrar();
        $registrar->onMethodsRegistering(new WsMethodsRegistering($this->server));
        return $this->readSpec();
    }

    /**
     * @return array<PathItem>
     */
    private function paths(OpenApi $openApi): array
    {
        // OpenApi::$paths is typed as Paths|null|array in cebe's annotations,
        // but our SpecBuilder always emits the `paths` member.
        $paths = $openApi->paths;
        self::assertInstanceOf(Paths::class, $paths);
        return $paths->getPaths();
    }

    private function buildRegistrar(): WsMethodRegistrar
    {
        // Endpoints are referenced only for first-class callable syntax — their
        // constructors (and hence DB connections) never run during registration.
        // PermissionService::isAGuest is called once and falls back to '' when
        // CurrentUser carries an empty status, never touching unset $conn /
        // $htmlService properties.
        return new WsMethodRegistrar(
            self::stub(CategoriesEndpoints::class),
            self::stub(GeneralEndpoints::class),
            self::stub(ImagesEndpoints::class),
            self::stub(TagsEndpoints::class),
            self::stub(UsersEndpoints::class),
            self::stub(PermissionService::class),
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function stub(string $class): object
    {
        return new \ReflectionClass($class)->newInstanceWithoutConstructor();
    }

    private function readSpec(): OpenApi
    {
        $json = new SpecBuilder($this->server)->build()->toJson();
        return Reader::readFromJson($json);
    }
}
