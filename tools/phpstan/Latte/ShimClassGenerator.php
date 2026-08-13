<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

use Closure;
use LogicException;
use Piwigo\Template\Latte\PiwigoExtension;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;

/**
 * Emits `LatteAnalysisShims`: one static method per PiwigoExtension
 * filter/function, carrying the real reflected signature -- parameter
 * names, types, by-ref/variadic flags, and (critically) real default
 * value expressions. Real defaults are the property PHPDoc
 * `Closure(...)` types structurally cannot express, and their absence
 * is what made PHPStan's ArgumentsNormalizer::reorderArgs() throw under
 * efabrica/phpstan-latte when a template call skipped an optional
 * middle parameter via named arguments (`combineScript(...,
 * require: 'jquery', ...)`).
 *
 * The compiled-template rewrite (LatteTemplateCompiler) redirects every
 * `($this->filters->X)(...)` / `($this->global->fn->X)($this, ...)`
 * property-invoke to these methods, so PHPStan checks template calls
 * against real signatures. Analysis-only: every body throws, nothing
 * here ever executes at runtime.
 */
final class ShimClassGenerator
{
    private const CLASS_NAME = 'LatteAnalysisShims';

    private const NAMESPACE = 'Piwigo\\Tools\\PhpStan\\Latte\\Generated';

    public function __construct(
        private readonly PiwigoExtension $extension,
    ) {}

    public function generate(): string
    {
        $filters = $this->extension->getFilters();
        $functions = $this->extension->getFunctions();

        // A name registered as both filter and function (translate, l10n,
        // is_admin, ...) becomes one shim method, so the two registrations
        // must genuinely be the same underlying callable -- if they ever
        // diverge, one merged method would silently misdeclare one of the
        // two call shapes.
        foreach (array_intersect_key($filters, $functions) as $name => $filterCallable) {
            $this->assertSameUnderlying($name, $filterCallable, $functions[$name]);
        }

        $merged = $filters + $functions;

        $methods = [];
        $templateAware = [];
        foreach ($merged as $name => $callable) {
            $reflection = new ReflectionFunction(Closure::fromCallable($callable));
            $methods[] = $this->renderMethod($name, $reflection);
            if ($this->isTemplateAware($reflection)) {
                $templateAware[] = $name;
            }
        }

        return $this->renderClass(
            array_keys($filters),
            array_keys($functions),
            $templateAware,
            $methods,
        );
    }

    private function assertSameUnderlying(string $name, callable $a, callable $b): void
    {
        $ra = new ReflectionFunction(Closure::fromCallable($a));
        $rb = new ReflectionFunction(Closure::fromCallable($b));
        $idA = ($ra->getClosureCalledClass()?->getName() ?? '') . '::' . $ra->getName();
        $idB = ($rb->getClosureCalledClass()?->getName() ?? '') . '::' . $rb->getName();
        if ($idA !== $idB) {
            throw new LogicException(sprintf(
                'Filter and function "%s" resolve to different callables (%s vs %s); '
                . 'the merged shim method would misdeclare one of them. Split the '
                . 'generator output before regenerating.',
                $name,
                $idA,
                $idB,
            ));
        }
    }

    private function isTemplateAware(ReflectionFunction $reflection): bool
    {
        $params = $reflection->getParameters();
        if ($params === []) {
            return false;
        }
        $type = $params[0]->getType();

        return $type instanceof ReflectionNamedType && $type->getName() === 'Latte\\Runtime\\Template';
    }

    private function renderMethod(string $name, ReflectionFunction $reflection): string
    {
        $params = array_map(
            fn (ReflectionParameter $p): string => $this->renderParameter($p),
            $reflection->getParameters(),
        );

        $returnType = $reflection->getReturnType() ?? $reflection->getTentativeReturnType();
        $returnSuffix = $returnType === null ? '' : ': ' . $this->renderType($returnType);

        $docblock = $this->renderDocblock($reflection, $this->synthesizedArrayParamLines($reflection));

        return ($docblock === '' ? '' : $docblock . "\n")
            . '    public static function ' . $name . '(' . implode(', ', $params) . ')' . $returnSuffix . "\n"
            . "    {\n"
            . "        throw new \\LogicException('Analysis-only shim; never executed.');\n"
            . "    }\n";
    }

    private function renderParameter(ReflectionParameter $param): string
    {
        $parts = [];
        $type = $param->getType();
        // Some internal-function parameters carry no reflectable type
        // (array_key_exists' $key, preg_match's &$matches) -- emit mixed
        // rather than an untyped parameter this project's own level-10
        // missingType rules would reject in the generated file.
        $parts[] = $type === null ? 'mixed' : $this->renderType($type);
        $parts[] = ($param->isPassedByReference() ? '&' : '')
            . ($param->isVariadic() ? '...' : '')
            . '$' . $param->getName();

        $rendered = implode(' ', $parts);

        if ($param->isVariadic() || ! $param->isOptional()) {
            return $rendered;
        }

        return $rendered . ' = ' . $this->renderDefault($param);
    }

    private function renderDefault(ReflectionParameter $param): string
    {
        if ($param->isDefaultValueConstant()) {
            $constant = $param->getDefaultValueConstantName();
            if ($constant !== null) {
                // Global constants and Class::CONST references both need a
                // leading backslash to survive the namespace change into
                // the generated file.
                return '\\' . ltrim($constant, '\\');
            }
        }

        if ($param->isDefaultValueAvailable()) {
            $value = $param->getDefaultValue();
            if ($value === []) {
                return '[]';
            }
            if ($value === null) {
                return 'null';
            }
            if (is_string($value) && preg_match('/[\x00-\x1f]/', $value) === 1) {
                // Control characters (trim()'s " \n\r\t\v\0" default) --
                // var_export() would emit them raw across source lines;
                // render an escaped double-quoted literal instead.
                return '"' . addcslashes($value, "\0..\x1f\\\"\$") . '"';
            }

            return var_export($value, true);
        }

        // Optional parameter with no reflectable default (possible for some
        // internal functions). null keeps the parameter optional; if the
        // declared type rejects null the PHPStan run over the generated
        // class itself reports it, so this can never silently misdeclare.
        return 'null';
    }

    private function renderType(ReflectionType $type): string
    {
        return ReflectionTypeRenderer::render($type);
    }

    /**
     * Parameters whose native type is (or unions with) bare `array` and
     * that get no `@param` line from the source docblock need a
     * synthesized `array<array-key, mixed>` line -- this project's own
     * level-10 missingType.iterableValue rule applies to the generated
     * file too.
     *
     * @return array<string, string> param name => synthesized @param line
     */
    private function synthesizedArrayParamLines(ReflectionFunction $reflection): array
    {
        $lines = [];
        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            if ($type === null) {
                continue;
            }
            $rendered = $this->renderType($type);
            if (preg_match('/(^|\|)\??array($|\|)/', $rendered) !== 1) {
                continue;
            }
            $docType = str_replace('array', 'array<array-key, mixed>', $rendered);
            $lines[$param->getName()] = '@param ' . $docType . ' $' . $param->getName();
        }

        return $lines;
    }

    /**
     * Copies the underlying callable's own `@param`/`@return` lines --
     * they carry array value types (`list<string>|string`, ...) the
     * native signature cannot -- and appends synthesized lines for
     * bare-array parameters the source docblock leaves uncovered.
     * Guarded against docblock types containing unqualified class names:
     * those would silently re-resolve in the generated file's own
     * namespace. No current callable has one (all class types live in
     * the native signatures); if one ever appears, fail generation
     * loudly instead of emitting a wrong type.
     *
     * @param array<string, string> $synthesized param name => @param line
     */
    private function renderDocblock(ReflectionFunction $reflection, array $synthesized): string
    {
        $doc = $reflection->getDocComment();
        $rawLines = [];
        if ($doc !== false) {
            $split = preg_split('/\R/', $doc);
            $rawLines = $split === false ? [] : $split;
        }

        $lines = [];
        foreach ($rawLines as $line) {
            if (preg_match('/@(param|return)\s/', $line) !== 1) {
                continue;
            }
            if (preg_match('/@(?:param|return)\s+[^$]*(?<![\\\\\w$])[A-Z][A-Za-z0-9_]*/', $line) === 1) {
                throw new LogicException(
                    'Docblock type with an unqualified class name cannot be copied into the '
                    . 'generated namespace: "' . trim($line) . '" ('
                    . ($reflection->getClosureCalledClass()?->getName() ?? 'function') . '::'
                    . $reflection->getName() . '). Fully qualify it in the source docblock '
                    . 'or extend ShimClassGenerator with use-map expansion.',
                );
            }
            if (preg_match('/@param\s.*\$(\w+)/', $line, $m) === 1) {
                unset($synthesized[$m[1]]);
            }
            $lines[] = '     ' . ltrim($line);
        }

        foreach ($synthesized as $line) {
            $lines[] = '     * ' . $line;
        }

        if ($lines === []) {
            return '';
        }

        return "    /**\n" . implode("\n", $lines) . "\n     */";
    }

    /**
     * @param list<string> $filters
     * @param list<string> $functions
     * @param list<string> $templateAware
     * @param list<string> $methods
     */
    private function renderClass(array $filters, array $functions, array $templateAware, array $methods): string
    {
        $exportList = self::renderNameList(...);

        return "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . 'namespace ' . self::NAMESPACE . ";\n\n"
            . "/**\n"
            . " * Generated by `bin/piwigo phpstan-latte:generate-shims` -- do not edit.\n"
            . " * One static method per PiwigoExtension filter/function, real reflected\n"
            . " * signatures with real default values; compiled-template analysis calls\n"
            . " * are rewritten to target these (see ShimClassGenerator's docblock).\n"
            . " *\n"
            . " * @api referenced only from generated analysis files under _analysis/,\n"
            . " * which shipmonk/dead-code-detector's call-graph does not always see\n"
            . " * (the directory is gitignored and empty until the first compile run).\n"
            . " */\n"
            . 'final class ' . self::CLASS_NAME . "\n"
            . "{\n"
            . '    public const FILTERS = ' . $exportList($filters) . ";\n\n"
            . '    public const FUNCTIONS = ' . $exportList($functions) . ";\n\n"
            . "    /**\n"
            . "     * Function names whose underlying callable's first parameter is\n"
            . "     * Latte\\Runtime\\Template -- Latte's FunctionExecutor passes these\n"
            . "     * the calling template as an implicit first argument, so the\n"
            . "     * compiled-call rewrite must NOT strip it for them.\n"
            . "     */\n"
            . '    public const TEMPLATE_AWARE = ' . $exportList($templateAware) . ";\n\n"
            . implode("\n", $methods)
            . "}\n";
    }

    /**
     * @param list<string> $names
     */
    private static function renderNameList(array $names): string
    {
        if ($names === []) {
            return '[]';
        }

        return "[\n" . implode('', array_map(
            static fn (string $n): string => "        '" . $n . "',\n",
            $names,
        )) . '    ]';
    }
}
