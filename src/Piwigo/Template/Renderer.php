<?php

declare(strict_types=1);

namespace Piwigo\Template;

use Latte\Runtime\Html;
use LogicException;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template as TemplateAttr;
use ReflectionClass;

/**
 * `render(View): Html` -- resolves the view's `#[Template]` attribute to
 * a `.latte` file and renders it via `Template::renderView()`. No
 * reflection-lookup cache: `ReflectionClass::getAttributes()` is a pure
 * introspection call over already-loaded class metadata (no I/O), called
 * at most a handful of times per request -- not worth a mutable cache on
 * this `readonly` class.
 */
final readonly class Renderer
{
    public function __construct(
        private CurrentTemplate $currentTemplate
    ) {}

    public function render(View $view): Html
    {
        $template = $this->currentTemplate->get();
        $file = self::resolveTemplateFile($view);

        return new Html($template->renderView($file, $view));
    }

    private static function resolveTemplateFile(View $view): string
    {
        $attributes = new ReflectionClass($view)
            ->getAttributes(TemplateAttr::class);
        if ($attributes === []) {
            throw new LogicException($view::class . ' has no #[Template] attribute');
        }

        return $attributes[0]->newInstance()->file;
    }
}
