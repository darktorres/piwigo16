<?php

declare(strict_types=1);

/**
 * Emits the live OpenAPI 3.1 spec to stdout — same document that
 * /ws/openapi.json serves at runtime, but without needing a running
 * Apache + DB.
 *
 * The script constructs the real WsMethodRegistrar with reflection-built
 * endpoint stubs (their DB-bound constructors are skipped), dispatches
 * WsMethodsRegistering against a bare PwgServer, then runs SpecBuilder
 * and prints the JSON.
 *
 * Usage:
 *   composer openapi:dump > openapi.json
 *
 * Pair with `npm run lint:openapi` to feed the output into redocly's
 * style-rule lint for CI.
 *
 * Exit code 0 on success; any uncaught exception aborts with a non-zero
 * status so CI fails loudly.
 */
require_once __DIR__ . '/../vendor/autoload.php';

use Piwigo\Event\Ws\WsMethodsRegistering;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\User;
use Piwigo\Ws\Method\CategoriesEndpoints;
use Piwigo\Ws\Method\CommentsEndpoints;
use Piwigo\Ws\Method\ExtensionsEndpoints;
use Piwigo\Ws\Method\GeneralEndpoints;
use Piwigo\Ws\Method\GroupsEndpoints;
use Piwigo\Ws\Method\ImagesEndpoints;
use Piwigo\Ws\Method\PermissionsEndpoints;
use Piwigo\Ws\Method\TagsEndpoints;
use Piwigo\Ws\Method\UsersEndpoints;
use Piwigo\Ws\OpenApi\SpecBuilder;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsMethodRegistrar;
use Symfony\Component\EventDispatcher\EventDispatcher as SymfonyEventDispatcher;

/**
 * Reflectively instantiates $class without invoking its constructor.
 *
 * @template T of object
 * @param class-string<T> $class
 * @return T
 */
function openapi_dump_stub(string $class): object
{
    return new ReflectionClass($class)->newInstanceWithoutConstructor();
}

CurrentUser::set(new User(
    id: 0,
    username: 'openapi_dump',
    email: '',
    language: 'en_US',
    theme: 'elegant',
    status: 'guest',
    enabledHigh: false,
    rawAttributes: ['username' => 'openapi_dump'],
));

$server         = openapi_dump_stub(PwgServer::class);
$dispatcherRef  = new ReflectionProperty($server, 'dispatcher');
$dispatcherRef->setValue($server, new SymfonyEventDispatcher());

$registrar = new WsMethodRegistrar(
    openapi_dump_stub(CategoriesEndpoints::class),
    openapi_dump_stub(CommentsEndpoints::class),
    openapi_dump_stub(ExtensionsEndpoints::class),
    openapi_dump_stub(GeneralEndpoints::class),
    openapi_dump_stub(GroupsEndpoints::class),
    openapi_dump_stub(ImagesEndpoints::class),
    openapi_dump_stub(PermissionsEndpoints::class),
    openapi_dump_stub(TagsEndpoints::class),
    openapi_dump_stub(UsersEndpoints::class),
    openapi_dump_stub(PermissionService::class),
);

$registrar->onMethodsRegistering(new WsMethodsRegistering($server));

echo new SpecBuilder($server)->build()->toJson(), "\n";
