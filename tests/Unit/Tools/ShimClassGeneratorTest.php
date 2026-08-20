<?php

declare(strict_types=1);

use Latte\Engine;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Template\Latte\PiwigoExtension;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\SessionServiceTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tools\PhpStan\Latte\Generated\LatteAnalysisShims;
use Piwigo\Tools\PhpStan\Latte\ShimClassGenerator;
use Piwigo\Users\CurrentUser;

function shim_generator_test_extension(): PiwigoExtension
{
    $currentConfig = CurrentConfigTestFactory::get();
    $currentUser = Kernel::container()->get(CurrentUser::class);
    if (! $currentUser instanceof CurrentUser) {
        throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
    }

    return new PiwigoExtension(
        TemplateTestFactory::build(),
        LangTestFactory::get(),
        new AccessLevelChecker($currentUser, $currentConfig),
        SessionServiceTestFactory::get(),
        UrlServiceTestFactory::build(),
    );
}

function shim_generator_test_engine(): Engine
{
    $engine = new Engine();
    $engine->addExtension(shim_generator_test_extension());

    return $engine;
}

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-shim-generator-test-' . bin2hex(random_bytes(8));
    $this->root = $root;
    mkdir($root, 0o777, true);
    Kernel::reset();
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentUserTestFactory::get()->attachGlobals();
    $this->generated = new ShimClassGenerator(shim_generator_test_engine())
        ->generate();
});

afterEach(function (): void {
    CurrentUserTestFactory::get()->reset();
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
    if (is_dir($this->root)) {
        exec('rm -rf ' . escapeshellarg($this->root));
    }
});

it('emits combineScript with the real signature including real default values', function (): void {
    expect($this->generated)->toContain(
        'public static function combineScript(string $id, ?string $load = null, ?string $require = null, '
        . "?string \$path = null, string|false \$version = '0'): void",
    );
});

it('emits variadic union types with FQCN class members', function (): void {
    expect($this->generated)->toContain(
        'public static function translate(string $key, \Latte\Runtime\Html|string|int|float|bool|null ...$args): string',
    );
});

it('types internal-function parameters reflection leaves untyped', function (): void {
    expect($this->generated)
        ->toContain('public static function array_key_exists(mixed $key, array $array): bool')
        ->toContain('mixed &$matches = null');
});

it('escapes control characters in string defaults instead of emitting them raw', function (): void {
    expect($this->generated)
        ->toContain('$characters = " \n\r\t\v\000"')
        ->not->toContain("\$characters = ' ");
});

it('copies array-typed docblock lines the native signature cannot express', function (): void {
    expect($this->generated)
        ->toContain('@param list<string>|string $search')
        ->toContain('@return list<string>');
});

it('declares name lists and detects Latte\'s own Template-aware functions', function (): void {
    expect($this->generated)
        ->toContain("'translate',")
        ->toContain("'checkUrl',")
        ->toContain("public const TEMPLATE_AWARE = [\n        'hasBlock',\n        'hasTemplate',\n    ]");
});

it('covers Latte\'s own built-in filters, with FilterInfo parameters dropped', function (): void {
    expect($this->generated)
        ->toContain('public static function checkUrl(mixed $s): string')
        ->toContain('public static function escape(mixed $s): string')
        // stripHtml is FilterInfo-aware at registration (Latte injects the
        // info at runtime); the shim keeps only the value parameter the
        // compiled call site actually passes.
        ->toContain('public static function stripHtml(\Stringable|string|null $s): string')
        ->not->toContain('FilterInfo $');
});

it('matches the checked-in artifact, so a PiwigoExtension change without regeneration fails here', function (): void {
    $checkedIn = file_get_contents(
        dirname(__DIR__, 3) . '/tools/phpstan/Latte/Generated/LatteAnalysisShims.php',
    );

    expect($this->generated)
        ->toBe($checkedIn);
});

it('shapes the real generated class to exactly the Engine-merged filter/function names', function (): void {
    $engine = shim_generator_test_engine();
    $methodNames = array_map(
        static fn (ReflectionMethod $m): string => strtolower($m->getName()),
        new ReflectionClass(LatteAnalysisShims::class)
            ->getMethods(ReflectionMethod::IS_STATIC),
    );
    // PHP methods dispatch case-insensitively, so Latte's case-variant
    // registrations (breaklines/breakLines) collapse to one shim method.
    $registered = array_unique(array_map(strtolower(...), [
        ...array_keys($engine->getFilters()),
        ...array_keys($engine->getFunctions()),
    ]));

    sort($methodNames);
    sort($registered);
    expect($methodNames)
        ->toBe($registered);
});
