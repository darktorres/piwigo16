<?php

declare(strict_types=1);

namespace Piwigo\Template;

/**
 * Engine-agnostic contract for rendering Piwigo page templates.
 *
 * Both the Smarty wrapper (Template.php) and the Latte wrapper (LatteEngine)
 * satisfy this interface. The dispatcher in TemplateRegistry routes a request
 * to the right engine based on the template-file extension (`.tpl` → Smarty,
 * `.latte` → Latte) so the two coexist during the §1.2 conversion window.
 */
interface TemplateEngine
{
    public function assign(string $name, mixed $value): void;

    /**
     * @param array<string, mixed> $params
     */
    public function render(string $template, array $params = []): string;
}
