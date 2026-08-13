<?php

declare(strict_types=1);

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

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-shim-generator-test-' . bin2hex(random_bytes(8));
    $this->root = $root;
    mkdir($root, 0o777, true);
    Kernel::reset();
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentUserTestFactory::get()->attachGlobals();
    $this->generated = (new ShimClassGenerator(shim_generator_test_extension()))->generate();
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
        . "?string \$path = null, string|false \$version = '0', bool \$template = false): void",
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

it('declares filter/function name lists and no template-aware functions', function (): void {
    expect($this->generated)
        ->toContain("public const FILTERS = [\n        'translate',")
        ->toContain("public const FUNCTIONS = [\n        'translate',")
        ->toContain('public const TEMPLATE_AWARE = []');
});

it('matches the checked-in artifact, so a PiwigoExtension change without regeneration fails here', function (): void {
    $checkedIn = file_get_contents(
        dirname(__DIR__, 3) . '/tools/phpstan/Latte/Generated/LatteAnalysisShims.php',
    );

    expect($this->generated)
        ->toBe($checkedIn);
});

it('shapes the real generated class to exactly the registered filter/function names', function (): void {
    $extension = shim_generator_test_extension();
    $methodNames = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        (new ReflectionClass(Piwigo\Tools\PhpStan\Latte\Generated\LatteAnalysisShims::class))
            ->getMethods(ReflectionMethod::IS_STATIC),
    );
    $registered = array_unique([
        ...array_keys($extension->getFilters()),
        ...array_keys($extension->getFunctions()),
    ]);

    sort($methodNames);
    sort($registered);
    expect($methodNames)
        ->toBe($registered);
});
