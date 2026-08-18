<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\UseUse;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Piwigo\Bootstrap\RouteDefinitions;
use Piwigo\Controller\Api\SessionController;
use Piwigo\Controller\Api\SessionLoginController;
use Piwigo\Controller\Api\SessionLogoutController;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\CsrfGuard;
use Piwigo\Tools\OpenApi\OperationDraft;
use Piwigo\Tools\OpenApi\ResponseBodyCallSiteVisitor;
use Symfony\Component\Routing\Route;
use Symfony\Component\Yaml\Yaml;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require __DIR__ . '/../vendor/autoload.php';

/**
 * One-time, throwaway scaffolding for the /api/v1 OpenAPI 3.2 spec's
 * first draft (see docs/PLAN.md P27 and the plan this tool was built
 * against). Emits one rough-draft YAML file per resource domain under
 * `openapi/.draft/` for Phase 2 to review, correct, and reshape by
 * hand -- never trusted verbatim, never re-run automatically as part of
 * any later workflow.
 *
 * What it gets right without help: path/method/path-params (from the
 * real, live RouteDefinitions::all() RouteCollection -- introspected at
 * runtime, not re-parsed from source), security requirements
 * (AdminGuard/CsrfGuard constructor-param reflection), and request body
 * field names + types for the 44/88 controllers that already have a
 * typed *Input DTO (real ReflectionClass on the DTO the controller's own
 * __invoke() body references via `XyzInput::fromArray(...)`).
 *
 * What it can only get partway, by design: response body field *names*
 * (never types -- see ResponseBodyCallSiteVisitor's own docblock for the
 * 3-tier breakdown; the unresolved tier is marked explicitly, not
 * silently emitted as an empty schema) and the 3 controllers with no
 * typed Input DTO at all (request body left as a TODO for those).
 */
/**
 * @return ReflectionClass<object>
 */
function classReflection(string $class): ReflectionClass
{
    if (! class_exists($class)) {
        throw new RuntimeException("Class does not exist: {$class}");
    }

    return new ReflectionClass($class);
}

/**
 * @return array{path: string, methods: list<string>, requirements: array<string, string>, controllerClass: string}
 */
function routeInfo(Route $route): array
{
    $defaults = $route->getDefaults();
    $controllerClass = $defaults['_controller'] ?? null;
    if (! is_string($controllerClass)) {
        throw new RuntimeException('Route missing a string _controller default: ' . $route->getPath());
    }

    $methods = [];
    foreach ($route->getMethods() as $method) {
        $methods[] = $method;
    }

    $requirements = [];
    foreach ($route->getRequirements() as $param => $pattern) {
        if (is_string($param) && is_string($pattern)) {
            $requirements[$param] = $pattern;
        }
    }

    return [
        'path' => $route->getPath(),
        'methods' => $methods,
        'requirements' => $requirements,
        'controllerClass' => $controllerClass,
    ];
}

function domainFor(string $controllerClass): string
{
    // Piwigo\Controller\Api\<Domain>\XyzController -> <Domain>; a bare
    // Piwigo\Controller\Api\XyzController (no subdirectory) is grouped by
    // hand below -- confirmed exhaustively against the real 7 root-level
    // controllers, not a general rule.
    if (preg_match('/^Piwigo\\\\Controller\\\\Api\\\\([A-Za-z]+)\\\\/', $controllerClass, $m) === 1) {
        return $m[1];
    }

    $rootLevelSessionControllers = [
        SessionController::class,
        SessionLoginController::class,
        SessionLogoutController::class,
    ];
    if (in_array($controllerClass, $rootLevelSessionControllers, true)) {
        return 'Session';
    }

    return 'Info-System';
}

/**
 * @return list<string> constructor-injected guard class basenames found
 *   (e.g. ['AdminGuard', 'CsrfGuard'])
 */
function guardsFor(string $controllerClass): array
{
    $ctor = classReflection($controllerClass)
        ->getConstructor();
    if ($ctor === null) {
        return [];
    }

    $guards = [];
    foreach ($ctor->getParameters() as $param) {
        $type = $param->getType();
        if (! $type instanceof ReflectionNamedType) {
            continue;
        }
        if ($type->getName() === AdminGuard::class) {
            $guards[] = 'AdminGuard';
        }
        if ($type->getName() === CsrfGuard::class) {
            $guards[] = 'CsrfGuard';
        }
    }

    return $guards;
}

/**
 * @return array<string, array{type: string, nullable: bool}>
 */
function reflectInputDto(string $dtoClass): array
{
    $ctor = classReflection($dtoClass)
        ->getConstructor();
    if ($ctor === null) {
        return [];
    }

    $schema = [];
    foreach ($ctor->getParameters() as $param) {
        $type = $param->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';
        $nullable = $type instanceof ReflectionNamedType && $type->allowsNull();
        $schema[$param->getName()] = [
            'type' => $typeName,
            'nullable' => $nullable,
        ];
    }

    return $schema;
}

/**
 * @param list<Node\Stmt> $methodStmts
 * @param ReflectionClass<object> $controllerReflection
 * @param list<Node\Stmt> $ast
 * @return array<string, array{type: string, nullable: bool}>|null
 */
function requestSchemaFor(array $methodStmts, NodeFinder $nodeFinder, ReflectionClass $controllerReflection, array $ast): ?array
{
    $inputCall = $nodeFinder->findFirst($methodStmts, static fn (Node $node): bool => $node instanceof StaticCall
        && $node->name instanceof Identifier
        && $node->name->name === 'fromArray'
        && $node->class instanceof Name
        && str_ends_with($node->class->toString(), 'Input'));
    if (! $inputCall instanceof StaticCall || ! $inputCall->class instanceof Name) {
        return null;
    }

    // The call site only ever has the short class name in scope (a `use`
    // import resolves it) -- resolve it the same way PHP itself would:
    // look for a matching `use` statement first, fall back to the
    // controller's own namespace (every real *Input DTO sits next to its
    // controller).
    $shortName = $inputCall->class->toString();
    $useStmt = $nodeFinder->findFirst($ast, static fn (Node $node): bool => $node instanceof UseUse && $node->name->getLast() === $shortName);
    $dtoClass = $useStmt instanceof UseUse
        ? $useStmt->name->toString()
        : $controllerReflection->getNamespaceName() . '\\' . $shortName;

    if (! class_exists($dtoClass)) {
        return null;
    }

    return reflectInputDto($dtoClass);
}

$parser = new ParserFactory()
    ->createForNewestSupportedVersion();
$nodeFinder = new NodeFinder();
$routes = RouteDefinitions::all();

/** @var array<string, list<OperationDraft>> */
$byDomain = [];

foreach ($routes->all() as $routeName => $route) {
    if (! str_starts_with($route->getPath(), '/api/v1')) {
        continue;
    }

    $info = routeInfo($route);
    $controllerClass = $info['controllerClass'];
    $domain = domainFor($controllerClass);
    $reflection = classReflection($controllerClass);

    $file = $reflection->getFileName();
    if ($file === false) {
        throw new RuntimeException("Could not resolve file for {$controllerClass}");
    }
    $source = file_get_contents($file);
    if ($source === false) {
        throw new RuntimeException("Could not read {$file}");
    }
    $parsed = $parser->parse($source);
    if ($parsed === null) {
        throw new RuntimeException("Could not parse {$file}");
    }
    $ast = array_values($parsed);

    $invokeMethod = $nodeFinder->findFirst($ast, static fn (Node $node): bool => $node instanceof ClassMethod && $node->name->toString() === '__invoke');
    $methodStmts = array_values($invokeMethod instanceof ClassMethod ? ($invokeMethod->stmts ?? []) : []);

    $requestSchema = requestSchemaFor($methodStmts, $nodeFinder, $reflection, $ast);

    $visitor = new ResponseBodyCallSiteVisitor();
    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);
    $traverser->traverse($methodStmts);

    $byDomain[$domain][] = new OperationDraft(
        routeName: $routeName,
        path: $info['path'],
        methods: $info['methods'],
        requirements: $info['requirements'],
        controllerClass: $controllerClass,
        guards: guardsFor($controllerClass),
        requestSchema: $requestSchema,
        responseCalls: $visitor->calls,
    );
}

$draftDir = __DIR__ . '/../openapi/.draft';
if (! is_dir($draftDir)) {
    mkdir($draftDir, 0o777, true);
}

$totalRoutes = 0;
foreach ($byDomain as $domain => $entries) {
    $totalRoutes += count($entries);
    $draft = array_map(static fn (OperationDraft $entry): array => $entry->toDraftArray(), $entries);

    $outFile = $draftDir . '/' . strtolower($domain) . '.yaml';
    file_put_contents($outFile, Yaml::dump($draft, 6, 2));
    fwrite(STDERR, 'wrote ' . count($entries) . " operations to {$outFile}\n");
}

fwrite(STDERR, "Total: {$totalRoutes} operations across " . count($byDomain) . " domains.\n");
