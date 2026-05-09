<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan;

use Efabrica\PHPStanLatte\Collector\CollectedData\CollectedResolvedNode;
use Efabrica\PHPStanLatte\LatteContext\LatteContext;
use Efabrica\PHPStanLatte\LatteTemplateResolver\LatteTemplateResolverResult;
use Efabrica\PHPStanLatte\LatteTemplateResolver\NodeLatteTemplateResolverInterface;
use Efabrica\PHPStanLatte\Template\Template;
use Efabrica\PHPStanLatte\Template\TemplateContext;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;

/**
 * phpstan-latte template resolver for Piwigo's bespoke render contract.
 *
 * Detects `$engine->render('path/to/template.latte', [...])` calls where
 * the receiver is a Piwigo\Template\TemplateEngine implementation, then
 * registers the path with phpstan-latte's analyser so unknown
 * filters/functions/classes inside the template are reported.
 *
 * Scope intentionally minimal for the §1.2 Wave 2 Phase A foundation:
 *   - Only string-literal (or otherwise constant-string-typed) paths
 *     ending in `.latte` are picked up. Dynamic/computed paths are
 *     skipped — they'd need richer expression-evaluation that's only
 *     worthwhile once production render() patterns stabilize.
 *   - Variables/components/forms/filters from the params array are
 *     dropped. Type-aware variable collection lands when typed
 *     page-context DTOs (PicturePageContext, AlbumPageContext, …) flow
 *     through controllers in later Phase B/C work.
 *
 * Wired as a service in phpstan.neon; phpstan-latte's
 * LatteTemplatesRule auto-discovers it via constructor-injected
 * `LatteTemplateResolverInterface[]`.
 */
final class PiwigoLatteEngineResolver implements NodeLatteTemplateResolverInterface
{
    private const PARAM_PATH = 'path';

    private const ENGINE_INTERFACE = 'Piwigo\\Template\\TemplateEngine';

    public function collect(Node $node, Scope $scope): array
    {
        if (!$node instanceof MethodCall) {
            return [];
        }
        if (!$node->name instanceof Identifier || $node->name->name !== 'render') {
            return [];
        }
        if (count($node->args) === 0) {
            return [];
        }

        $engineType = new ObjectType(self::ENGINE_INTERFACE);
        if (!$engineType->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        $arg0 = $node->args[0];
        if (!$arg0 instanceof Arg) {
            return [];
        }

        $constantStrings = $scope->getType($arg0->value)->getConstantStrings();
        if (count($constantStrings) === 0) {
            return [];
        }

        $resolved = [];
        foreach ($constantStrings as $constString) {
            $path = $constString->getValue();
            if (!str_ends_with($path, '.latte')) {
                continue;
            }
            $resolved[] = new CollectedResolvedNode(
                static::class,
                $scope->getFile(),
                [self::PARAM_PATH => $path],
            );
        }

        return $resolved;
    }

    public function resolve(
        CollectedResolvedNode $resolvedNode,
        LatteContext $latteContext,
    ): LatteTemplateResolverResult {
        $template = new Template(
            $resolvedNode->getParam(self::PARAM_PATH),
            null,
            null,
            new TemplateContext(),
        );

        return new LatteTemplateResolverResult([$template]);
    }
}
