<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use PHPStan\PhpDocParser\Printer\Printer;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use Throwable;

/**
 * Reads one `TemplatePageContext` class and produces its
 * `{template variable name => PHPStan type}` map -- the data the
 * compiled-template `@var` injection needs. No PHPStan `Scope`
 * involved: the 130 real context classes are `final readonly` with
 * native-typed promoted constructor properties plus `@param` docblocks,
 * so native reflection + phpstan/phpdoc-parser covers them (that's what
 * makes running as a pre-step outside PHPStan possible at all).
 *
 * Typing rules for each `'key' => expression` pair in `toArray()`:
 *  - `$this->prop` -> the property's declared type (docblock `@param`
 *    on the promoted constructor parameter wins over the native type,
 *    since it carries array value types).
 *  - `$this->prop->member` -> the member property's declared type on
 *    the target class (value objects like LangCode's `public string
 *    $value`), or the backing type for `->value` on a backed enum.
 *  - anything else -> the first `$this->prop` inside the expression,
 *    with a notice (approximation, widening-safe) -- or `mixed` with a
 *    notice when there is none.
 *
 * Docblock types are FQCN-expanded through the context class's own
 * `use` map before leaving this class: the annotated compiled template
 * lives in a different namespace, where an unqualified `TabSheetEntry`
 * would silently resolve to nothing.
 */
final class ContextVariableExtractor
{
    private readonly PhpDocParser $phpDocParser;

    private readonly Lexer $lexer;

    public function __construct()
    {
        $config = new ParserConfig([]);
        $this->lexer = new Lexer($config);
        $constExprParser = new ConstExprParser($config);
        $this->phpDocParser = new PhpDocParser($config, new TypeParser($config, $constExprParser), $constExprParser);
    }

    public function extract(string $contextClass): ExtractedVariables
    {
        if (! class_exists($contextClass)) {
            return new ExtractedVariables([], ["context class does not exist: {$contextClass}"]);
        }

        $reflection = new ReflectionClass($contextClass);
        $file = $reflection->getFileName();
        if ($file === false) {
            return new ExtractedVariables([], ["context class has no source file: {$contextClass}"]);
        }

        $notices = [];
        $propertyTypes = $this->propertyTypes($reflection, $notices);

        $classNode = $this->parseClassNode($file, $reflection->getShortName());
        if ($classNode === null) {
            return new ExtractedVariables([], ["cannot locate class node for {$contextClass} in {$file}"]);
        }

        $toArray = $classNode->getMethod('toArray');
        if (! $toArray instanceof ClassMethod) {
            return new ExtractedVariables([], ["no toArray() method on {$contextClass}"]);
        }

        $vars = [];
        $finder = new NodeFinder();

        foreach ($finder->findInstanceOf($toArray, Array_::class) as $array) {
            foreach ($array->items as $item) {
                if ($item->key instanceof String_) {
                    $vars[$item->key->value] = $this->typeOfExpression(
                        $item->value,
                        $contextClass,
                        $reflection,
                        $propertyTypes,
                        $item->key->value,
                        $notices,
                    );
                } elseif ($item->key !== null) {
                    $notices[] = "dynamic array key in {$contextClass}::toArray() (line {$item->getStartLine()}) -- variable unknowable statically";
                }
            }
        }

        // `$result['literal'] = $this->prop;` -- the dominant shape for
        // contexts with conditional variables (ConfigurationWatermark,
        // MenubarIdentification, ...): the guard means the key is only
        // sometimes present at runtime, but the declared type still holds
        // whenever it is.
        foreach ($finder->findInstanceOf($toArray, Assign::class) as $assign) {
            if (! $assign->var instanceof ArrayDimFetch) {
                continue;
            }
            $dim = $assign->var->dim;
            if ($dim instanceof String_) {
                $vars[$dim->value] = $this->typeOfExpression(
                    $assign->expr,
                    $contextClass,
                    $reflection,
                    $propertyTypes,
                    $dim->value,
                    $notices,
                );
            } elseif ($dim !== null) {
                $notices[] = "dynamic array-dim assignment in {$contextClass}::toArray() (line {$assign->getStartLine()}) -- variable unknowable statically";
            }
        }

        if ($vars === []) {
            $notices[] = "no literal-keyed variables found in {$contextClass}::toArray()";
        }
        ksort($vars);

        return new ExtractedVariables($vars, $notices);
    }

    /**
     * The framework-level variables `Template` itself assigns outside
     * any page context -- always present for every render. From reading
     * Template.php's own internal assign()/append() sites: `pwg` (line
     * ~224), `lang_info` (~242), `themeconf` (append(), ~527),
     * `ROOT_URL`/`ROOT_PATH` (parse(), ~818/824),
     * `PLUGIN_PICTURE_BUTTONS`/`PLUGIN_INDEX_BUTTONS` (~1252/1275).
     *
     * @return array<string, string>
     */
    public function frameworkGlobals(): array
    {
        return [
            'ROOT_URL' => 'string',
            'ROOT_PATH' => 'string',
            'pwg' => '\\Piwigo\\Template\\TemplateAdapter',
            'lang_info' => 'array<string, mixed>',
            'themeconf' => 'array<string, mixed>',
            'PLUGIN_PICTURE_BUTTONS' => 'array<array-key, mixed>',
            'PLUGIN_INDEX_BUTTONS' => 'array<array-key, mixed>',
        ];
    }

    /**
     * The `$theme_template_vars` bulk spread (`Template::loadThemeconf()`
     * `include`s each theme's `themeconf.inc.php`, then bulk-assigns
     * whatever that file put in `$theme_template_vars`). Not arbitrary in
     * practice: the files are real, parseable array literals, so their
     * keys ARE statically enumerable. Values are typed when they follow
     * the one real shape (`Template::currentConfig()->prop`, resolved via
     * reflection through currentConfig()'s return type), `mixed` with a
     * notice otherwise. Union across every theme, same widening-only
     * semantics as everything else.
     *
     * @return array{vars: array<string, string>, notices: list<string>}
     */
    public function themeTemplateVars(string $root): array
    {
        $vars = [];
        $notices = [];
        $shallow = glob(rtrim($root, '/') . '/themes/*/themeconf.inc.php');
        $nested = glob(rtrim($root, '/') . '/themes/*/*/themeconf.inc.php');
        $files = [...($shallow === false ? [] : $shallow), ...($nested === false ? [] : $nested)];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            if ($source === false) {
                continue;
            }
            $ast = (new ParserFactory())->createForNewestSupportedVersion()
                ->parse($source);
            if ($ast === null) {
                continue;
            }
            foreach ((new NodeFinder())->findInstanceOf($ast, Assign::class) as $assign) {
                if (! $assign->var instanceof Variable || $assign->var->name !== 'theme_template_vars') {
                    continue;
                }
                if (! $assign->expr instanceof Array_) {
                    $notices[] = "non-literal \$theme_template_vars in {$file} -- keys unknowable statically";
                    continue;
                }
                foreach ($assign->expr->items as $item) {
                    if (! $item->key instanceof String_) {
                        $notices[] = "dynamic \$theme_template_vars key in {$file} (line {$item->getStartLine()})";
                        continue;
                    }
                    $type = $this->currentConfigChainType($item->value);
                    if ($type === null) {
                        $type = 'mixed';
                        $notices[] = "\$theme_template_vars['{$item->key->value}'] in {$file} has an unrecognized value shape -- typed mixed";
                    }
                    $existing = $vars[$item->key->value] ?? null;
                    $vars[$item->key->value] = $existing === null || $existing === $type
                        ? $type
                        : $existing . '|' . $type;
                }
            }
        }
        ksort($vars);

        return [
            'vars' => $vars,
            'notices' => $notices,
        ];
    }

    /**
     * Types the one real themeconf value shape:
     * `Template::currentConfig()->prop` -- reflect currentConfig()'s
     * declared return class, then the property's declared type.
     */
    private function currentConfigChainType(Expr $expr): ?string
    {
        if (! $expr instanceof PropertyFetch || ! $expr->name instanceof Identifier) {
            return null;
        }
        $call = $expr->var;
        if (! $call instanceof Node\Expr\StaticCall
            || ! $call->name instanceof Identifier
            || $call->name->name !== 'currentConfig'
        ) {
            return null;
        }

        $method = new \ReflectionMethod('Piwigo\\Template\\Template', 'currentConfig');
        $returnType = $method->getReturnType();
        if (! $returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
            return null;
        }
        $configClassName = $returnType->getName();
        if (! class_exists($configClassName)) {
            return null;
        }
        $configClass = new ReflectionClass($configClassName);
        if (! $configClass->hasProperty($expr->name->name)) {
            return null;
        }
        $propType = $configClass->getProperty($expr->name->name)
            ->getType();

        return $propType === null ? null : ReflectionTypeRenderer::render($propType);
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param array<string, string> $propertyTypes
     * @param list<string> $notices
     */
    private function typeOfExpression(
        Expr $expr,
        string $contextClass,
        ReflectionClass $reflection,
        array $propertyTypes,
        string $key,
        array &$notices,
    ): string {
        $direct = $this->directPropertyName($expr);
        if ($direct !== null) {
            return $propertyTypes[$direct] ?? 'mixed';
        }

        if ($expr instanceof PropertyFetch && $expr->name instanceof Identifier) {
            $inner = $this->directPropertyName($expr->var);
            if ($inner !== null) {
                $chained = $this->chainedPropertyType($reflection, $inner, $expr->name->name);
                if ($chained !== null) {
                    return $chained;
                }
            }
        }

        $fallback = (new NodeFinder())->findFirst(
            $expr,
            fn (Node $n): bool => $this->directPropertyName($n) !== null,
        );
        if ($fallback instanceof Expr) {
            $name = $this->directPropertyName($fallback);
            if ($name !== null && isset($propertyTypes[$name])) {
                $notices[] = "'{$key}' in {$contextClass}::toArray() is a computed expression -- approximated as \${$name}'s type ({$propertyTypes[$name]})";

                return $propertyTypes[$name];
            }
        }

        $notices[] = "'{$key}' in {$contextClass}::toArray() has no property reference -- typed mixed";

        return 'mixed';
    }

    private function directPropertyName(Node $node): ?string
    {
        if ($node instanceof PropertyFetch
            && $node->var instanceof Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier
        ) {
            return $node->name->name;
        }

        return null;
    }

    /**
     * `$this->prop->member` -- the real narrowing step for value objects
     * and enums: `$this->langCode->value` where LangCode is a
     * `final readonly` VO with `public string $value` resolves to
     * `string`, and `->value` on a backed enum resolves to its backing
     * type.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function chainedPropertyType(ReflectionClass $reflection, string $property, string $member): ?string
    {
        if (! $reflection->hasProperty($property)) {
            return null;
        }
        $type = $reflection->getProperty($property)
            ->getType();
        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }
        $class = $type->getName();

        if (enum_exists($class) && $member === 'value') {
            $backing = (new ReflectionEnum($class))->getBackingType();

            return $backing instanceof ReflectionNamedType ? $backing->getName() : null;
        }

        if (class_exists($class)) {
            $target = new ReflectionClass($class);
            if ($target->hasProperty($member)) {
                $memberType = $target->getProperty($member)
                    ->getType();

                return $memberType === null ? null : ReflectionTypeRenderer::render($memberType);
            }
        }

        return null;
    }

    /**
     * Property name => PHPStan type string. Docblock `@param` (promoted
     * constructor properties -- the dominant real shape) and `@var`
     * lines win over native types; both are FQCN-expanded through the
     * file's use map.
     *
     * @param ReflectionClass<object> $reflection
     * @param list<string> $notices
     * @return array<string, string>
     */
    private function propertyTypes(ReflectionClass $reflection, array &$notices): array
    {
        $nameContext = $this->buildNameContext($reflection);

        $docTypes = [];
        $constructor = $reflection->getConstructor();
        if ($constructor !== null) {
            $doc = $constructor->getDocComment();
            if ($doc !== false) {
                foreach ($this->parsePhpDoc($doc)->getParamTagValues() as $tag) {
                    $docTypes[ltrim($tag->parameterName, '$')] = $this->expandedTypeString($tag->type, $nameContext);
                }
            }
        }

        $types = [];
        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();

            $doc = $property->getDocComment();
            if ($doc !== false) {
                foreach ($this->parsePhpDoc($doc)->getVarTagValues() as $tag) {
                    $docTypes[$name] = $this->expandedTypeString($tag->type, $nameContext);
                }
            }

            if (isset($docTypes[$name])) {
                $types[$name] = $docTypes[$name];
                continue;
            }

            $nativeType = $property->getType();
            if ($nativeType === null) {
                $notices[] = "property \${$name} on {$reflection->getName()} has no type -- mixed";
                $types[$name] = 'mixed';
                continue;
            }
            $types[$name] = ReflectionTypeRenderer::render($nativeType);
        }

        return $types;
    }

    private function parsePhpDoc(string $doc): PhpDocNode
    {
        return $this->phpDocParser->parse(new TokenIterator($this->lexer->tokenize($doc)));
    }

    /**
     * Recursively rewrites every class-like identifier in a phpdoc type
     * node to its FQCN (leading backslash) when the file's use map
     * resolves it to a real class/interface/enum -- generic reflection
     * over the node tree, so every phpdoc-parser node kind (unions,
     * generics, array shapes, ...) is covered without enumerating them.
     */
    private function expandedTypeString(TypeNode $type, \PhpParser\NameContext $nameContext): string
    {
        $this->expandIdentifiers($type, $nameContext);

        // Printer, not (string)-casting -- the AST's own __toString()
        // re-parenthesizes unions as "(A | B)", while Printer emits
        // canonical "A|B".
        return (new Printer())->print($type);
    }

    private function expandIdentifiers(object $node, \PhpParser\NameContext $nameContext): void
    {
        if ($node instanceof IdentifierTypeNode) {
            $name = $node->name;
            if ($name === '' || str_starts_with($name, '\\') || preg_match('/^[A-Z]/', $name) !== 1) {
                return;
            }

            try {
                $resolved = $nameContext->getResolvedClassName(new Name($name))
                    ->toString();
            } catch (Throwable) {
                return;
            }
            if (class_exists($resolved) || interface_exists($resolved) || enum_exists($resolved)) {
                $node->name = '\\' . $resolved;
            }

            return;
        }

        foreach (get_object_vars($node) as $value) {
            if (is_object($value)) {
                $this->expandIdentifiers($value, $nameContext);
            } elseif (is_array($value)) {
                foreach ($value as $element) {
                    if (is_object($element)) {
                        $this->expandIdentifiers($element, $nameContext);
                    }
                }
            }
        }
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function buildNameContext(ReflectionClass $reflection): \PhpParser\NameContext
    {
        $nameResolver = new NameResolver();
        $file = $reflection->getFileName();
        if ($file !== false) {
            $source = file_get_contents($file);
            if ($source !== false) {
                $ast = (new ParserFactory())->createForNewestSupportedVersion()
                    ->parse($source);
                if ($ast !== null) {
                    $traverser = new NodeTraverser();
                    $traverser->addVisitor($nameResolver);
                    $traverser->traverse($ast);
                }
            }
        }

        return $nameResolver->getNameContext();
    }

    private function parseClassNode(string $file, string $shortName): ?ClassLike
    {
        $source = file_get_contents($file);
        if ($source === false) {
            return null;
        }
        $ast = (new ParserFactory())->createForNewestSupportedVersion()
            ->parse($source);
        if ($ast === null) {
            return null;
        }

        $found = (new NodeFinder())->findFirst(
            $ast,
            static fn (Node $n): bool => $n instanceof ClassLike && $n->name?->toString() === $shortName,
        );

        return $found instanceof ClassLike ? $found : null;
    }
}
