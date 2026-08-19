<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

use FilesystemIterator;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * php-parser pass over `src/Piwigo/` finding the three real render/
 * context call shapes -- `parse('x.latte')` (literal at arg 0),
 * `assignVarFromTemplate('VAR', 'x.latte')` (literal at arg 1),
 * `assignContext(new SomeContext(...))` -- and resolving each template
 * literal to real file(s). No receiver-type check: over-matching merely
 * compiles a harmless extra template, under-matching is impossible for
 * these shapes (every real site is a Template/TemplateInterface call,
 * confirmed by the predecessor resolver's grep audit).
 *
 * Path resolution is carried unchanged from the retired
 * PiwigoLatteEngineResolver: a bare basename like `'header.latte'` is
 * only meaningful relative to a Template instance's runtime search
 * dirs, so the calling file's own location scopes the search --
 * `Admin`/`Controller\Admin` files resolve only under
 * `themes/admin/default/template/` (admin has exactly one theme),
 * `Mail` files only under `themes/default/template/mail/`
 * (MailService::getMailTemplate() already scopes its instance there),
 * everything else is genuinely theme-polymorphic and resolves against
 * `themes/default/` + `themes/standard_pages/`.
 * A zero-match scoped lookup widens to the full tree (with a notice)
 * rather than silently resolving to nothing.
 */
final class TemplateCallSiteScanner
{
    /**
     * @var array<string, int> method name => literal-path argument index
     */
    private const array METHOD_ARG_INDEX = [
        'assignVarFromTemplate' => 1,
        'parse' => 0,
    ];

    /**
     * @var array<string, list<string>>|null basename => real absolute paths
     */
    private ?array $fullIndex = null;

    public function __construct(
        private readonly string $root,
    ) {}

    public function scan(): CallSiteScanResult
    {
        $templatesByClass = [];
        $contextsByClass = [];
        $assignedTemplateVars = [];
        $notices = [];

        $parser = new ParserFactory()
            ->createForNewestSupportedVersion();

        foreach ($this->candidateFiles() as $file) {
            $source = file_get_contents($file);
            if ($source === false) {
                $notices[] = "unreadable: {$file}";
                continue;
            }

            try {
                $ast = $parser->parse($source);
            } catch (Throwable $e) {
                $notices[] = "parse failure: {$file}: {$e->getMessage()}";
                continue;
            }
            if ($ast === null) {
                continue;
            }

            $visitor = new TemplateCallSiteVisitor();

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->calls as $call) {
                $this->collectCall(
                    $call['class'],
                    $call['method'],
                    $call['node'],
                    $file,
                    $templatesByClass,
                    $contextsByClass,
                    $assignedTemplateVars,
                    $notices,
                );
            }
        }

        ksort($templatesByClass);
        ksort($contextsByClass);
        $assignedTemplateVars = array_keys($assignedTemplateVars);
        sort($assignedTemplateVars);

        return new CallSiteScanResult($templatesByClass, $contextsByClass, $assignedTemplateVars, $notices);
    }

    /**
     * @param array<string, list<string>> $templatesByClass
     * @param array<string, list<string>> $contextsByClass
     * @param array<string, true> $assignedTemplateVars
     * @param list<string> $notices
     */
    private function collectCall(
        string $class,
        string $method,
        MethodCall $node,
        string $file,
        array &$templatesByClass,
        array &$contextsByClass,
        array &$assignedTemplateVars,
        array &$notices,
    ): void {
        if ($method === 'assignContext') {
            $arg = $node->args[0] ?? null;
            if (! $arg instanceof Arg) {
                return;
            }
            if ($arg->value instanceof New_ && $arg->value->class instanceof Name) {
                $contextClass = $arg->value->class->toString();
                if (! in_array($contextClass, $contextsByClass[$class] ?? [], true)) {
                    $contextsByClass[$class][] = $contextClass;
                }

                return;
            }
            // Every real call site passes `new X(...)` inline (confirmed:
            // 139/139 at scan-design time). Anything else is a new pattern
            // the association logic can't see -- surface it, don't drop it.
            $notices[] = "assignContext with a non-`new` argument in {$class} ({$file}:{$node->getStartLine()})";

            return;
        }

        $argIndex = self::METHOD_ARG_INDEX[$method] ?? null;
        if ($argIndex === null) {
            return;
        }

        // assignVarFromTemplate('VARNAME', 'x.latte') also *defines* a
        // template variable (the rendered output, always Html) consumed by
        // whatever template renders later -- collect the literal names so
        // VariableMapBuilder can type them instead of leaving e.g.
        // $ADMIN_CONTENT undefined in admin_shell.latte.
        if ($method === 'assignVarFromTemplate') {
            $varArg = $node->args[0] ?? null;
            if ($varArg instanceof Arg && $varArg->value instanceof String_) {
                $assignedTemplateVars[$varArg->value->value] = true;
            }
        }

        $arg = $node->args[$argIndex] ?? null;
        if (! $arg instanceof Arg || ! $arg->value instanceof String_) {
            // Variable/computed template args are legitimate internal
            // forwarding (Template::assignVarFromTemplate() -> parse());
            // real resolution happens at the outer literal call sites.
            return;
        }
        $literal = $arg->value->value;
        if (! str_ends_with($literal, '.latte')) {
            return;
        }

        $paths = $this->findRealPaths($literal, $this->scopedRoots($file));
        if ($paths === []) {
            $paths = $this->findRealPaths($literal, [
                $this->root . '/themes',
            ]);
            if ($paths !== []) {
                $notices[] = "fallback-widened lookup for '{$literal}' from {$class} ({$file})";
            }
        }
        if ($paths === []) {
            $notices[] = "unresolvable template '{$literal}' from {$class} ({$file}:{$node->getStartLine()})";

            return;
        }

        foreach ($paths as $path) {
            if (! in_array($path, $templatesByClass[$class] ?? [], true)) {
                $templatesByClass[$class][] = $path;
            }
        }
    }

    /**
     * Cheap pre-filter: only files mentioning a template literal or an
     * assignContext call can produce a match, so only those (~150 of
     * ~2500) are worth a full php-parser pass -- this scanner runs ahead
     * of every `composer analyse:phpstan`.
     *
     * @return list<string>
     */
    private function candidateFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root . '/src/Piwigo', FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $source = file_get_contents($path);
            if ($source === false) {
                continue;
            }
            if (str_contains($source, '.latte') || str_contains($source, 'assignContext(')) {
                $files[] = $path;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function scopedRoots(string $callingFile): array
    {
        $normalized = str_replace('\\', '/', $callingFile);

        if (str_contains($normalized, '/src/Piwigo/Admin/') || str_contains($normalized, '/src/Piwigo/Controller/Admin/')) {
            return [$this->root . '/themes/admin/default/template'];
        }

        if (str_contains($normalized, '/src/Piwigo/Mail/')) {
            return [$this->root . '/themes/default/template/mail'];
        }

        return [
            $this->root . '/themes/default/template',
            $this->root . '/themes/standard_pages/template',
        ];
    }

    /**
     * A literal is usually a bare basename (`'header.latte'`), but some
     * real call sites pass a subpath (`'include/selected_tags.inc.latte'`
     * in GalleryController) -- the index stays basename-keyed, and a
     * subpath literal additionally requires the whole relative suffix to
     * match. (The retired resolver missed this shape and silently
     * resolved those call sites to nothing -- found by this scanner's own
     * real-tree test, not assumed.)
     *
     * @param list<string> $roots
     * @return list<string>
     */
    private function findRealPaths(string $literal, array $roots): array
    {
        $matches = $this->fullIndex()[basename($literal)] ?? [];
        $requiredSuffix = '/' . ltrim($literal, '/');

        $scoped = [];
        foreach ($matches as $realPath) {
            if (! str_ends_with($realPath, $requiredSuffix)) {
                continue;
            }
            foreach ($roots as $root) {
                if (str_starts_with($realPath, $root . '/')) {
                    $scoped[] = $realPath;
                    break;
                }
            }
        }

        return $scoped;
    }

    /**
     * basename => real absolute paths across the whole template tree,
     * built once per scan.
     *
     * @return array<string, list<string>>
     */
    private function fullIndex(): array
    {
        if ($this->fullIndex !== null) {
            return $this->fullIndex;
        }

        $index = [];
        $root = $this->root . '/themes';
        if (is_dir($root)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                if (! $file->isFile() || $file->getExtension() !== 'latte') {
                    continue;
                }
                $realPath = $file->getRealPath();
                if ($realPath === false) {
                    continue;
                }
                $index[$file->getFilename()][] = $realPath;
            }
        }
        foreach ($index as &$paths) {
            sort($paths);
        }

        $this->fullIndex = $index;

        return $index;
    }
}
