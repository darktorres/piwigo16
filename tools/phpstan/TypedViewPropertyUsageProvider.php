<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan;

use Piwigo\Tools\PhpStan\Latte\LatteTemplateFiles;
use Piwigo\Tools\PhpStan\Latte\TemplateTypeScanner;
use ReflectionProperty;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * Recognizes every real `.latte` file's own `{templateType FqcnHere}`
 * declaration (scanned the same way `VariableMapContext::build()` does
 * for the Latte-analysis pipeline itself) as a real reader of that
 * class's public properties -- P40's "contract-only" View-adjacent
 * classes (e.g. `Piwigo\Menu\Projection\MenubarBlockView`,
 * `Piwigo\Calendar\Projection\MonthCalendarView`) are never
 * `Renderer::render()`-ed and so never constructed by any in-tree PHP
 * code at all; `VariableMapBuilder`'s own `ReflectionClass(...)
 * ->getProperties()` walk (the actual "reader") only reflects on the
 * class's declared TYPES, never touches an instance, which is
 * invisible to shipmonk/dead-code-detector's own `ReflectionUsageProvider`
 * (that one only recognizes a statically-known class name reaching
 * `ReflectionClass::getProperties()`, and `$viewClass` here is a
 * runtime string read back from the very `.latte` scan this provider
 * repeats) -- same "our own equivalent of a call-graph edge shipmonk
 * can't see" shape as `GessoHookMethodUsageProvider`.
 */
final class TypedViewPropertyUsageProvider extends ReflectionBasedMemberUsageProvider
{
    /**
     * @var array<string, true>|null
     */
    private ?array $templateTypeClasses = null;

    public function __construct(
        private readonly string $projectRoot,
    ) {}

    #[\Override]
    protected function shouldMarkPropertyAsRead(ReflectionProperty $property): ?VirtualUsageData
    {
        $className = $property->getDeclaringClass()
            ->getName();

        if (! isset($this->templateTypeClasses()[$className])) {
            return null;
        }

        return VirtualUsageData::withNote(
            "Reflected by VariableMapBuilder for {$className}'s own {templateType} declaration (tools/phpstan/Latte/), not read via normal PHP property access."
        );
    }

    /**
     * @return array<string, true>
     */
    private function templateTypeClasses(): array
    {
        if ($this->templateTypeClasses !== null) {
            return $this->templateTypeClasses;
        }

        $classes = [];
        foreach (TemplateTypeScanner::scan(LatteTemplateFiles::discover($this->projectRoot)) as $class) {
            $classes[$class] = true;
        }

        return $this->templateTypeClasses = $classes;
    }
}
