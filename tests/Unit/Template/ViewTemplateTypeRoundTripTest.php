<?php

declare(strict_types=1);

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template as TemplateAttr;

/**
 * Every real `View` implementation's `#[Template]` attribute must point at
 * a `.latte` file that itself declares `{templateType <that same class>}`
 * -- the two halves of the mechanism (`Renderer::render()` resolving the
 * file, `VariableMapBuilder`'s `{templateType}` branch resolving the
 * class) staying in sync.
 */
test('every View implementation round-trips with its #[Template] file\'s own {templateType} declaration', function (): void {
    $sourceFile = new ReflectionClass(View::class)->getFileName();
    expect($sourceFile)
        ->not->toBeFalse();

    /** @var array<string, string> $classMap */
    $classMap = require dirname((string) $sourceFile, 4) . '/vendor/composer/autoload_classmap.php';

    $checked = 0;
    foreach ($classMap as $class => $file) {
        if (! str_starts_with($class, 'Piwigo\\') || ! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if (! $reflection->implementsInterface(View::class)) {
            continue;
        }

        $attributes = $reflection->getAttributes(TemplateAttr::class);
        expect($attributes)
            ->not->toBe([], "{$class} implements View but has no #[Template] attribute");

        $templateFile = $attributes[0]->newInstance()->file;
        $root = dirname((string) $sourceFile, 4) . '/';
        $realPath = null;
        foreach (['themes/default/template/', 'themes/admin/default/template/', ''] as $prefix) {
            if (is_file($root . $prefix . $templateFile)) {
                $realPath = $root . $prefix . $templateFile;
                break;
            }
        }
        expect($realPath)
            ->not->toBeNull("{$class}'s #[Template('{$templateFile}')] does not resolve to a real file");

        $source = file_get_contents((string) $realPath);
        expect($source)
            ->not->toBeFalse();

        if (preg_match('/\{templateType\s+([^\s}]+)\}/', (string) $source, $m) !== 1) {
            throw new RuntimeException("{$templateFile} has no {templateType} declaration, but {$class} points at it via #[Template]");
        }

        expect(ltrim($m[1], '\\'))
            ->toBe(ltrim($class, '\\'), "{$templateFile}'s {templateType} declares {$m[1]}, but {$class}'s own #[Template] points here");

        $checked++;
    }

    expect($checked)
        ->toBeGreaterThan(0);
});
